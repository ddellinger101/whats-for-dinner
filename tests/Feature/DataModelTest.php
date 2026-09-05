<?php

namespace Tests\Feature;

use App\Enums\ComponentType;
use App\Enums\IngredientCategory;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Enums\Rating;
use App\Models\GroceryListItem;
use App\Models\HouseholdSetting;
use App\Models\Ingredient;
use App\Models\IngredientUseByWindow;
use App\Models\InventoryFlag;
use App\Models\MealComponent;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RepeaterItem;
use App\Models\SimpleItem;
use App\Models\WeeklyHouseholdSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
    }

    public function test_recipe_stores_enums_and_json_collections(): void
    {
        $recipe = Recipe::create([
            'name' => 'Chicken Tacos',
            'protein_type' => ProteinType::Chicken,
            'category_tags' => ['comfort_food', 'keto'],
            'recipe_links' => ['https://example.com/a', 'https://example.com/b'],
            'base_servings' => 4,
            'is_keto' => true,
        ]);

        $fresh = $recipe->fresh();

        $this->assertSame(ProteinType::Chicken, $fresh->protein_type);
        $this->assertSame(Rating::Unrated, $fresh->rating);
        $this->assertCount(2, $fresh->recipe_links);
        $this->assertCount(2, $fresh->category_tags);
    }

    /** Spec 4.3: round the multiplier up, not the ingredient amounts. */
    public function test_serving_multiplier_rounds_the_multiplier_up(): void
    {
        $recipe = Recipe::create(['name' => 'Chili', 'base_servings' => 4]);

        $this->assertSame(1, $recipe->servingMultiplierFor(2));
        $this->assertSame(1, $recipe->servingMultiplierFor(4));
        // 5 people on a recipe for 4 scales as if serving 8, not 5.
        $this->assertSame(2, $recipe->servingMultiplierFor(5));
    }

    /** Spec 4.2.1: thumbs-down is excluded from suggestions but still exists. */
    public function test_selectable_scope_excludes_thumbs_down(): void
    {
        Recipe::create(['name' => 'Good', 'rating' => Rating::ThumbsUp]);
        Recipe::create(['name' => 'Bad', 'rating' => Rating::ThumbsDown]);

        $this->assertSame(['Good'], Recipe::selectable()->pluck('name')->all());
        $this->assertSame(2, Recipe::count());
    }

    /** Spec 4.2.2: keto mode hides off-diet recipes from suggestions. */
    public function test_diet_mode_filters_only_when_active(): void
    {
        Recipe::create(['name' => 'Keto Bowl', 'is_keto' => true]);
        Recipe::create(['name' => 'Pasta', 'is_keto' => false]);

        $this->assertSame(2, Recipe::matchingDiet(null)->count());
        $this->assertSame(1, Recipe::matchingDiet('keto')->count());
    }

    public function test_slot_holds_multiple_components_with_one_primary(): void
    {
        $recipe = Recipe::create(['name' => 'Tacos', 'base_servings' => 4]);
        $side = SimpleItem::create(['name' => 'Side Salad']);

        $entry = MealPlanEntry::create([
            'date' => '2026-09-09',
            'slot' => MealSlot::Dinner,
            'household_size_used' => 5,
        ]);

        MealComponent::create([
            'meal_plan_entry_id' => $entry->id,
            'component_type' => ComponentType::Recipe,
            'recipe_id' => $recipe->id,
            'is_primary' => true,
            'servings_needed' => 5,
        ]);
        MealComponent::create([
            'meal_plan_entry_id' => $entry->id,
            'component_type' => ComponentType::SimpleItem,
            'simple_item_id' => $side->id,
            'is_primary' => false,
            'servings_needed' => 5,
        ]);

        $entry->load('components.recipe', 'components.simpleItem');

        $this->assertCount(2, $entry->components);
        $this->assertSame('Tacos', $entry->primaryComponent()->displayName());
        $this->assertTrue($entry->primaryComponent()->drivesSuggestionLogic());
        $this->assertSame('Tacos', $entry->primaryRecipe()->name);
    }

    /** Spec 4.5: compound items expand; single-concept items use their own name. */
    public function test_simple_item_grocery_lines(): void
    {
        $compound = SimpleItem::create([
            'name' => 'Turkey Sandwich',
            'grocery_breakdown' => ['turkey', 'bread', 'cheese', 'mayo'],
        ]);
        $single = SimpleItem::create(['name' => 'Grapes']);

        $this->assertSame(['turkey', 'bread', 'cheese', 'mayo'], $compound->groceryLines());
        $this->assertTrue($compound->isCompound());
        $this->assertSame(['Grapes'], $single->groceryLines());
        $this->assertFalse($single->isCompound());
    }

    /** Spec 4.6: an in-stock ingredient is skipped when building the list. */
    public function test_inventory_flag_reports_stock(): void
    {
        $beef = Ingredient::create([
            'name' => 'Ground Beef',
            'category' => IngredientCategory::Protein,
            'shelf_life_days' => 3,
        ]);
        $onion = Ingredient::create([
            'name' => 'Onion',
            'category' => IngredientCategory::Produce,
            'shelf_life_days' => 14,
        ]);

        InventoryFlag::create([
            'ingredient_id' => $beef->id,
            'has_stock' => true,
            'note' => '2 lbs in freezer',
        ]);

        $this->assertTrue($beef->fresh()->hasStock());
        $this->assertFalse($onion->fresh()->hasStock());
    }

    public function test_recipe_ingredient_pivot_carries_quantity(): void
    {
        $recipe = Recipe::create(['name' => 'Soup', 'base_servings' => 4]);
        $carrot = Ingredient::create([
            'name' => 'Carrot',
            'category' => IngredientCategory::Produce,
            'shelf_life_days' => 21,
        ]);

        $recipe->ingredients()->attach($carrot->id, [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'quantity_per_serving' => 0.5,
            'unit' => 'cup',
        ]);

        $loaded = $recipe->fresh()->ingredients->first();
        $this->assertSame('Carrot', $loaded->name);
        $this->assertSame('cup', $loaded->pivot->unit);
        $this->assertEquals(0.5, (float) $loaded->pivot->quantity_per_serving);
    }

    /** Spec 4.1/4.2.3: only windows still open can boost a suggestion. */
    public function test_use_by_windows_open_scope(): void
    {
        $sourCream = Ingredient::create([
            'name' => 'Sour Cream',
            'category' => IngredientCategory::Dairy,
            'shelf_life_days' => 12,
        ]);
        $cilantro = Ingredient::create([
            'name' => 'Cilantro',
            'category' => IngredientCategory::Produce,
            'shelf_life_days' => 5,
        ]);

        IngredientUseByWindow::create([
            'ingredient_id' => $sourCream->id,
            'week_start_date' => '2026-09-07',
            'purchase_date' => '2026-09-06',
            'expires_on' => '2026-09-18',
        ]);
        IngredientUseByWindow::create([
            'ingredient_id' => $cilantro->id,
            'week_start_date' => '2026-09-07',
            'purchase_date' => '2026-09-06',
            'expires_on' => '2026-09-11',
        ]);

        $asOf = Carbon::parse('2026-09-15');
        $open = IngredientUseByWindow::open($asOf)->get();

        $this->assertCount(1, $open);
        $this->assertSame($sourCream->id, $open->first()->ingredient_id);
    }

    /** Spec 3: weekday servings are fixed; weekends follow the custody toggle. */
    public function test_household_schedule_respects_custody_toggle(): void
    {
        $monday = WeeklyHouseholdSchedule::where('day_of_week', 1)->first();
        $saturday = WeeklyHouseholdSchedule::where('day_of_week', 6)->first();

        $this->assertSame(2, $monday->servingsFor(true));
        $this->assertSame(2, $monday->servingsFor(false));
        $this->assertSame(5, $saturday->servingsFor(true));
        $this->assertSame(2, $saturday->servingsFor(false));
    }

    /** Spec 4.1: use-by windows date from the upcoming shopping day. */
    public function test_upcoming_shopping_date(): void
    {
        $settings = HouseholdSetting::current();
        $this->assertSame(0, $settings->shopping_day_of_week);

        // Wednesday 2026-09-09 -> next Sunday is 2026-09-13.
        $this->assertSame(
            '2026-09-13',
            $settings->upcomingShoppingDate(Carbon::parse('2026-09-09'))->toDateString(),
        );
        // On the shopping day itself, today counts as upcoming.
        $this->assertSame(
            '2026-09-13',
            $settings->upcomingShoppingDate(Carbon::parse('2026-09-13'))->toDateString(),
        );
    }

    /** Spec 4.6: repeaters surface once due, independent of the meal plan. */
    public function test_repeater_due_scope_and_purchase(): void
    {
        $paper = RepeaterItem::create(['item_name' => 'Paper Towels', 'frequency_days' => 14]);
        RepeaterItem::create([
            'item_name' => 'Dog Food',
            'frequency_days' => 30,
            'next_due_date' => '2026-12-01',
        ]);

        $due = RepeaterItem::due(Carbon::parse('2026-09-05'))->pluck('item_name')->all();
        $this->assertSame(['Paper Towels'], $due);

        $paper->markPurchased(Carbon::parse('2026-09-05'));
        $this->assertSame('2026-09-19', $paper->fresh()->next_due_date->toDateString());
        $this->assertCount(0, RepeaterItem::due(Carbon::parse('2026-09-06'))->get());
    }

    /** Spec 3: removing a component retracts the grocery lines it generated. */
    public function test_grocery_items_are_traceable_to_their_component(): void
    {
        $recipe = Recipe::create(['name' => 'Stew', 'base_servings' => 4]);
        $entry = MealPlanEntry::create([
            'date' => '2026-09-10',
            'slot' => MealSlot::Dinner,
            'household_size_used' => 2,
        ]);
        $component = MealComponent::create([
            'meal_plan_entry_id' => $entry->id,
            'component_type' => ComponentType::Recipe,
            'recipe_id' => $recipe->id,
            'is_primary' => true,
            'servings_needed' => 2,
        ]);

        GroceryListItem::create([
            'item_name' => 'Beef Chuck',
            'quantity' => 2,
            'unit' => 'lb',
            'source' => 'auto_recipe',
            'added_date' => '2026-09-05',
            'source_component_id' => $component->id,
        ]);

        $this->assertCount(1, $component->groceryListItems);
        $this->assertSame(1, GroceryListItem::needed()->count());

        // The FK nulls out rather than deleting the line, so a manually edited
        // item is never silently lost.
        $component->delete();
        $this->assertNull(GroceryListItem::first()->source_component_id);
    }
}
