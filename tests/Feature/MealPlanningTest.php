<?php

namespace Tests\Feature;

use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Models\GroceryListItem;
use App\Models\HouseholdSetting;
use App\Models\Ingredient;
use App\Models\IngredientUseByWindow;
use App\Models\InventoryFlag;
use App\Models\MealComponent;
use App\Models\Recipe;
use App\Models\RepeaterItem;
use App\Models\SimpleItem;
use App\Services\GroceryListBuilder;
use App\Services\MealPlanner;
use App\Services\UseByWindowTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class MealPlanningTest extends TestCase
{
    use RefreshDatabase;

    private MealPlanner $planner;

    private UseByWindowTracker $windows;

    private GroceryListBuilder $grocery;

    /** Wednesday. The plan week runs Sun 2026-09-06 to Sat 2026-09-12. */
    private const WEDNESDAY = '2026-09-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->planner = new MealPlanner;
        $this->windows = new UseByWindowTracker;
        $this->grocery = new GroceryListBuilder;
    }

    /**
     * @param  array<string, array{IngredientCategory, float|null, string|null}>  $ingredients
     */
    private function makeRecipe(
        string $name,
        array $ingredients = [],
        int $baseServings = 4,
        ProteinType $protein = ProteinType::Chicken,
    ): Recipe {
        $recipe = Recipe::create([
            'name' => $name,
            'protein_type' => $protein,
            'base_servings' => $baseServings,
            'ingredients_status' => $ingredients === []
                ? IngredientsStatus::NotYetAdded
                : IngredientsStatus::ManuallyEntered,
        ]);

        foreach ($ingredients as $ingredientName => [$category, $perServing, $unit]) {
            $ingredient = Ingredient::firstOrCreate(
                ['name' => $ingredientName],
                ['category' => $category, 'shelf_life_days' => $category->defaultShelfLifeDays()],
            );

            $recipe->ingredients()->attach($ingredient->id, [
                'id' => (string) Str::uuid(),
                'quantity_per_serving' => $perServing,
                'unit' => $unit,
            ]);
        }

        return $recipe->fresh();
    }

    // ------------------------------------------------------------- use-by

    /** Spec 4.1: perishables get a window; dry pantry goods do not. */
    public function test_primary_recipe_opens_windows_for_perishables_only(): void
    {
        $recipe = $this->makeRecipe('Tacos', [
            'Sour Cream' => [IngredientCategory::Dairy, 0.25, 'cup'],
            'Cilantro' => [IngredientCategory::Produce, 0.1, 'bunch'],
            'Taco Shells' => [IngredientCategory::PantryDry, 2, 'each'],
        ]);

        $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $tracked = IngredientUseByWindow::with('ingredient')
            ->get()->pluck('ingredient.name')->sort()->values()->all();

        $this->assertSame(['Cilantro', 'Sour Cream'], $tracked);
    }

    /** Spec 4.1: one window per ingredient per week, refreshed not duplicated. */
    public function test_second_recipe_refreshes_the_same_window(): void
    {
        $first = $this->makeRecipe('Salad', ['Sour Cream' => [IngredientCategory::Dairy, 0.2, 'cup']]);
        Ingredient::where('name', 'Sour Cream')->update(['shelf_life_days' => 5]);

        $this->planner->setPrimaryRecipe(Carbon::parse('2026-09-07'), MealSlot::Dinner, $first);
        $this->assertSame(1, IngredientUseByWindow::count());
        $firstExpiry = IngredientUseByWindow::first()->expires_on->copy();

        // A longer shelf life on the same ingredient should push the window out.
        Ingredient::where('name', 'Sour Cream')->update(['shelf_life_days' => 20]);
        $second = $this->makeRecipe('Dip', ['Sour Cream' => [IngredientCategory::Dairy, 0.5, 'cup']]);
        $this->planner->setPrimaryRecipe(Carbon::parse('2026-09-10'), MealSlot::Dinner, $second);

        $this->assertSame(1, IngredientUseByWindow::count(), 'window should refresh, not duplicate');
        $this->assertTrue(IngredientUseByWindow::first()->expires_on->greaterThan($firstExpiry));
    }

    /** Spec 4.1: sides do not start a clock. */
    public function test_side_components_do_not_open_windows(): void
    {
        $side = $this->makeRecipe('Garlic Bread', ['Butter' => [IngredientCategory::Dairy, 0.1, 'cup']]);

        $this->planner->addSide(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $side);

        $this->assertSame(0, IngredientUseByWindow::count());
    }

    /** Spec 4.7: a migrated recipe with no ingredients must not break anything. */
    public function test_recipe_without_ingredients_opens_no_windows(): void
    {
        $recipe = $this->makeRecipe('Burgers');

        $component = $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $this->assertSame(0, IngredientUseByWindow::count());
        $this->assertSame(0, GroceryListItem::count());
        $this->assertNotNull($component->id);
    }

    /** Spec 4.2.3: expired windows stop counting. */
    public function test_only_unexpired_windows_are_open(): void
    {
        $milk = Ingredient::create([
            'name' => 'Milk', 'category' => IngredientCategory::Dairy, 'shelf_life_days' => 3,
        ]);
        $rice = Ingredient::create([
            'name' => 'Rice', 'category' => IngredientCategory::Produce, 'shelf_life_days' => 30,
        ]);

        $purchase = Carbon::parse('2026-09-06');
        $this->windows->openWindow($milk, Carbon::parse(self::WEDNESDAY), $purchase);
        $this->windows->openWindow($rice, Carbon::parse(self::WEDNESDAY), $purchase);

        // Milk expires 2026-09-09; rice runs to 2026-10-06.
        $open = $this->windows->openIngredientIds(Carbon::parse('2026-09-11'));

        $this->assertSame([$rice->id], $open->all());
    }

    // ------------------------------------------------------------ grocery

    /** Spec 4.3: multiplier rounds up, then applies to the whole recipe yield. */
    public function test_ingredients_are_scaled_by_the_rounded_up_multiplier(): void
    {
        // 0.25 lb/serving x 4 servings = 1 lb for the recipe as written.
        $recipe = $this->makeRecipe('Chili', [
            'Ground Beef' => [IngredientCategory::Protein, 0.25, 'lb'],
        ], baseServings: 4);

        // Wednesday feeds 5, so the multiplier is ceil(5/4) = 2, giving 2 lb.
        $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $item = GroceryListItem::firstOrFail();
        $this->assertSame('Ground Beef', $item->item_name);
        $this->assertEquals(2.0, (float) $item->quantity);
        $this->assertSame('lb', $item->unit);
    }

    /** Spec 4.6: anything already in the freezer is skipped. */
    public function test_in_stock_ingredients_are_not_added(): void
    {
        $recipe = $this->makeRecipe('Stew', [
            'Ground Beef' => [IngredientCategory::Protein, 0.25, 'lb'],
            'Carrot' => [IngredientCategory::Produce, 0.5, 'cup'],
        ]);

        InventoryFlag::create([
            'ingredient_id' => Ingredient::where('name', 'Ground Beef')->firstOrFail()->id,
            'has_stock' => true,
            'note' => '2 lbs in freezer',
        ]);

        $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $this->assertSame(['Carrot'], GroceryListItem::pluck('item_name')->all());
    }

    /** Spec 4.5: single-concept items become one line under their own name. */
    public function test_single_concept_simple_item_uses_its_own_name(): void
    {
        $grapes = SimpleItem::create(['name' => 'Grapes']);

        $this->planner->addSide(Carbon::parse(self::WEDNESDAY), MealSlot::Lunch, $grapes);

        $item = GroceryListItem::firstOrFail();
        $this->assertSame('Grapes', $item->item_name);
        $this->assertSame(GroceryItemSource::AutoSimpleItem, $item->source);
        // A head count, not a recipe-style multiplier (spec 4.3).
        $this->assertEquals(5.0, (float) $item->quantity);
    }

    /** Spec 4.5: a compound item expands into its saved breakdown. */
    public function test_compound_simple_item_expands_to_its_breakdown(): void
    {
        $sandwich = SimpleItem::create([
            'name' => 'Turkey Sandwich',
            'grocery_breakdown' => ['turkey', 'bread', 'cheese', 'mayo'],
        ]);

        $this->planner->addSide(Carbon::parse(self::WEDNESDAY), MealSlot::Lunch, $sandwich);

        $this->assertSame(
            ['bread', 'cheese', 'mayo', 'turkey'],
            GroceryListItem::pluck('item_name')->sort()->values()->all(),
        );
    }

    /** Removing a meal must not strip items someone already shopped for. */
    public function test_removing_a_component_retracts_only_unpurchased_auto_lines(): void
    {
        $recipe = $this->makeRecipe('Curry', [
            'Ground Beef' => [IngredientCategory::Protein, 0.25, 'lb'],
            'Carrot' => [IngredientCategory::Produce, 0.5, 'cup'],
        ]);

        $component = $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);
        $this->grocery->addManual('Dish Soap');
        GroceryListItem::where('item_name', 'Carrot')->firstOrFail()->markPurchased();

        $this->planner->removeComponent($component);

        $this->assertSame(
            ['Carrot', 'Dish Soap'],
            GroceryListItem::pluck('item_name')->sort()->values()->all(),
        );
    }

    /** Changing your mind about dinner must not leave the old shopping behind. */
    public function test_replacing_the_primary_retracts_the_previous_groceries(): void
    {
        $first = $this->makeRecipe('Tacos', [
            'Ground Beef' => [IngredientCategory::Protein, 0.25, 'lb'],
        ]);
        $second = $this->makeRecipe('Salmon', [
            'Salmon Fillet' => [IngredientCategory::Protein, 0.3, 'lb'],
        ], protein: ProteinType::Seafood);

        $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $first);
        $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $second);

        $this->assertSame(['Salmon Fillet'], GroceryListItem::pluck('item_name')->all());
        $this->assertSame(1, MealComponent::where('is_primary', true)->count());
    }

    /** Spec 4.6: repeaters appear when due, without stacking up. */
    public function test_due_repeaters_are_added_once(): void
    {
        RepeaterItem::create(['item_name' => 'Paper Towels', 'frequency_days' => 14]);
        RepeaterItem::create([
            'item_name' => 'Dog Food', 'frequency_days' => 30, 'next_due_date' => '2026-12-01',
        ]);

        $added = $this->grocery->syncDueRepeaters(Carbon::parse(self::WEDNESDAY));
        $this->assertSame(['Paper Towels'], $added->pluck('item_name')->all());

        // Running again the next day must not add a second line.
        $this->grocery->syncDueRepeaters(Carbon::parse('2026-09-10'));
        $this->assertSame(1, GroceryListItem::where('source', GroceryItemSource::Repeater->value)->count());
    }

    /** Pinning servings must rescale what is already on the list. */
    public function test_pinning_servings_rescales_the_grocery_lines(): void
    {
        $recipe = $this->makeRecipe('Roast', [
            'Beef Chuck' => [IngredientCategory::Protein, 0.5, 'lb'],
        ], baseServings: 4);

        $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);
        // 5 people, multiplier 2: 0.5 * 4 * 2 = 4 lb.
        $this->assertEquals(4.0, (float) GroceryListItem::firstOrFail()->quantity);

        $entry = $this->planner->slotFor(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner);
        $this->planner->setServings($entry, 12);

        // 12 people, multiplier 3: 0.5 * 4 * 3 = 6 lb.
        $this->assertEquals(6.0, (float) GroceryListItem::firstOrFail()->quantity);
        $this->assertTrue($entry->fresh()->servings_manually_set);
    }

    /** An untouched auto line stays needed until someone acts on it. */
    public function test_auto_added_lines_start_as_needed(): void
    {
        $recipe = $this->makeRecipe('Soup', ['Carrot' => [IngredientCategory::Produce, 0.5, 'cup']]);

        $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $item = GroceryListItem::firstOrFail();
        $this->assertSame(GroceryItemStatus::Needed, $item->status);
        $this->assertSame(GroceryItemSource::AutoRecipe, $item->source);
        $this->assertNotNull($item->ingredient_id);
    }

    /** Spec 4.1: use-by windows date from the upcoming shopping day. */
    public function test_windows_date_from_the_shopping_day(): void
    {
        $this->assertSame(0, HouseholdSetting::current()->shopping_day_of_week);

        $recipe = $this->makeRecipe('Pasta', ['Cream' => [IngredientCategory::Dairy, 0.2, 'cup']]);
        Ingredient::where('name', 'Cream')->update(['shelf_life_days' => 10]);

        $this->planner->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $window = IngredientUseByWindow::firstOrFail();
        // The Sunday after Wed 2026-09-09 is 2026-09-13, plus 10 days.
        $this->assertSame('2026-09-13', $window->purchase_date->toDateString());
        $this->assertSame('2026-09-23', $window->expires_on->toDateString());
    }
}
