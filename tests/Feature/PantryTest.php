<?php

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\Recipe;
use App\Models\User;
use App\Services\GroceryListBuilder;
use App\Services\InventoryService;
use App\Services\MealPlanner;
use App\Services\RecipeSuggestionRanker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The pantry: a rough picture of what is in, feeding the suggestion ranker.
 */
class PantryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private InventoryService $inventory;

    private const WEDNESDAY = '2026-09-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->user = User::factory()->create();
        $this->inventory = new InventoryService;
        Carbon::setTestNow(Carbon::parse(self::WEDNESDAY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ingredient(string $name, IngredientCategory $category, int $shelfLife): Ingredient
    {
        return Ingredient::firstOrCreate(
            ['name' => $name],
            ['category' => $category, 'shelf_life_days' => $shelfLife],
        );
    }

    private function recipeWith(string $name, array $ingredients, int $baseServings = 4): Recipe
    {
        $recipe = Recipe::create([
            'name' => $name,
            'protein_type' => ProteinType::Beef,
            'base_servings' => $baseServings,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        foreach ($ingredients as $ingredientName => $perServing) {
            $ingredient = $this->ingredient($ingredientName, IngredientCategory::Produce, 6);
            $recipe->ingredients()->attach($ingredient->id, [
                'id' => (string) Str::uuid(),
                'quantity_per_serving' => $perServing,
                'unit' => 'lb',
            ]);
        }

        return $recipe->fresh();
    }

    // ----------------------------------------------------- stocking the pantry

    /** Ticking something off is the app's one reliable "this came in". */
    public function test_buying_something_puts_it_in_the_pantry(): void
    {
        $beef = $this->ingredient('Ground beef', IngredientCategory::Protein, 3);

        $item = GroceryListItem::create([
            'item_name' => 'Ground beef',
            'quantity' => 2,
            'unit' => 'lb',
            'source' => 'auto_recipe',
            'status' => 'needed',
            'added_date' => self::WEDNESDAY,
            'ingredient_id' => $beef->id,
        ]);

        $this->actingAs($this->user)->post(route('grocery.toggle', $item))->assertRedirect();

        $flag = InventoryFlag::where('ingredient_id', $beef->id)->firstOrFail();
        $this->assertTrue($flag->has_stock);
        $this->assertEqualsWithDelta(2.0, (float) $flag->quantity, 0.001);
        $this->assertSame('lb', $flag->unit);
        // Shelf life sets the use-by: bought Wednesday, good for three days.
        $this->assertSame('2026-09-12', $flag->expires_on->toDateString());
    }

    /** A manual line has no ingredient to match, so it is not tracked. */
    public function test_an_item_with_no_ingredient_is_not_stocked(): void
    {
        $item = (new GroceryListBuilder)->addManual('Birthday candles');

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));

        $this->assertSame(0, InventoryFlag::count());
    }

    /** Buying more adds to what is there and pushes the date out. */
    public function test_buying_more_of_something_adds_to_it(): void
    {
        $beef = $this->ingredient('Ground beef', IngredientCategory::Protein, 3);

        $this->inventory->recordPurchase(GroceryListItem::create([
            'item_name' => 'Ground beef', 'quantity' => 1, 'unit' => 'lb',
            'source' => 'manual', 'status' => 'purchased',
            'added_date' => '2026-09-07', 'ingredient_id' => $beef->id,
        ]), Carbon::parse('2026-09-07'));

        $this->inventory->recordPurchase(GroceryListItem::create([
            'item_name' => 'Ground beef', 'quantity' => 2, 'unit' => 'lb',
            'source' => 'manual', 'status' => 'purchased',
            'added_date' => self::WEDNESDAY, 'ingredient_id' => $beef->id,
        ]), Carbon::parse(self::WEDNESDAY));

        $flag = InventoryFlag::where('ingredient_id', $beef->id)->firstOrFail();
        $this->assertSame(1, InventoryFlag::count());
        $this->assertEqualsWithDelta(3.0, (float) $flag->quantity, 0.001);
        $this->assertSame('2026-09-12', $flag->expires_on->toDateString());
    }

    // ---------------------------------------------------- emptying the pantry

    /** Cooking takes ingredients out, scaled the way the shopping was. */
    public function test_marking_a_recipe_made_deducts_its_ingredients(): void
    {
        // 0.5/serving x 4 servings = 2 lb as written.
        $recipe = $this->recipeWith('Chilli', ['Onion' => 0.5], baseServings: 4);
        $onion = Ingredient::where('name', 'Onion')->firstOrFail();

        $this->inventory->add($onion, 5, 'lb');

        (new MealPlanner)->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $this->actingAs($this->user)->post(route('recipes.cooked', $recipe))->assertRedirect();

        // Wednesday feeds 5, so multiplier 2: 4 lb used, 1 left.
        $flag = InventoryFlag::where('ingredient_id', $onion->id)->firstOrFail();
        $this->assertEqualsWithDelta(1.0, (float) $flag->quantity, 0.001);
        $this->assertTrue($flag->has_stock);
    }

    public function test_using_the_last_of_something_clears_the_stock_flag(): void
    {
        $recipe = $this->recipeWith('Chilli', ['Onion' => 0.5], baseServings: 4);
        $onion = Ingredient::where('name', 'Onion')->firstOrFail();
        $this->inventory->add($onion, 2, 'lb');

        $this->inventory->consumeForRecipe($recipe, 4);

        $flag = InventoryFlag::where('ingredient_id', $onion->id)->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $flag->quantity, 0.001);
        $this->assertFalse($flag->has_stock);
    }

    /**
     * A null quantity means "some, amount unknown". Cooking with it cannot tell
     * you whether any is left, so guessing would either strand it or bin it.
     */
    public function test_an_unquantified_item_survives_being_cooked_with(): void
    {
        $recipe = $this->recipeWith('Chilli', ['Onion' => 0.5]);
        $onion = Ingredient::where('name', 'Onion')->firstOrFail();
        $this->inventory->add($onion, null, null);

        $this->inventory->consumeForRecipe($recipe, 4);

        $flag = InventoryFlag::where('ingredient_id', $onion->id)->firstOrFail();
        $this->assertTrue($flag->has_stock);
        $this->assertNull($flag->quantity);
        $this->assertSame('In stock', $flag->amountLabel());
    }

    // -------------------------------------------------- feeding the suggestions

    /** The whole point: what is going off should steer dinner. */
    public function test_something_going_off_boosts_recipes_that_use_it(): void
    {
        $usesIt = $this->recipeWith('Sour Cream Bake', ['Sour cream' => 0.25]);
        Recipe::create(['name' => 'Plain Roast', 'rating' => \App\Enums\Rating::ThumbsUp]);

        $sourCream = Ingredient::where('name', 'Sour cream')->firstOrFail();
        $sourCream->update(['shelf_life_days' => 2]);
        $this->inventory->add($sourCream, 1, 'cup');

        $ranked = (new RecipeSuggestionRanker)->for(Carbon::parse(self::WEDNESDAY));

        // Beats a thumbs-up recipe purely on clearing the fridge.
        $this->assertSame('Sour Cream Bake', $ranked->first()->recipe->name);
        $this->assertSame(['Sour cream'], $ranked->first()->usesUp);
        $this->assertSame('uses up: Sour cream', $ranked->first()->reason());
    }

    /** Plenty of time left is not a reason to cook something. */
    public function test_something_with_weeks_left_does_not_boost(): void
    {
        $recipe = $this->recipeWith('Rice Bowl', ['Rice' => 0.25]);
        $rice = Ingredient::where('name', 'Rice')->firstOrFail();
        $rice->update(['shelf_life_days' => 300]);
        $this->inventory->add($rice, 2, 'cup');

        $ranked = (new RecipeSuggestionRanker)->for(Carbon::parse(self::WEDNESDAY));

        $this->assertFalse($ranked->firstWhere('recipe.name', 'Rice Bowl')->isBoosted());
    }

    /** Something marked gone must stop steering anything. */
    public function test_marking_it_gone_removes_it_from_the_use_up_signal(): void
    {
        $recipe = $this->recipeWith('Sour Cream Bake', ['Sour cream' => 0.25]);
        $sourCream = Ingredient::where('name', 'Sour cream')->firstOrFail();
        $sourCream->update(['shelf_life_days' => 2]);
        $flag = $this->inventory->add($sourCream, 1, 'cup');

        $this->assertTrue((new RecipeSuggestionRanker)->for(Carbon::parse(self::WEDNESDAY))
            ->firstWhere('recipe.name', 'Sour Cream Bake')->isBoosted());

        $this->actingAs($this->user)->post(route('pantry.gone', $flag))->assertRedirect();

        $this->assertFalse((new RecipeSuggestionRanker)->for(Carbon::parse(self::WEDNESDAY))
            ->firstWhere('recipe.name', 'Sour Cream Bake')->isBoosted());
    }

    // --------------------------------------------------------------- the screen

    public function test_the_pantry_screen_separates_what_is_going_off(): void
    {
        $soon = $this->ingredient('Sour cream', IngredientCategory::Dairy, 2);
        $later = $this->ingredient('Rice', IngredientCategory::PantryDry, 300);
        $this->inventory->add($soon, 1, 'cup');
        $this->inventory->add($later, 2, 'cup');

        $response = $this->actingAs($this->user)->get(route('pantry'))->assertOk();

        $this->assertSame(['Sour cream'], $response->viewData('atRisk')->map->ingredient->pluck('name')->all());
        $response->assertSee('Use these up')->assertSee('Rice');
    }

    public function test_something_can_be_added_by_hand(): void
    {
        $this->actingAs($this->user)
            ->post(route('pantry.store'), ['name' => 'Sour cream', 'quantity' => 1, 'unit' => 'tub'])
            ->assertRedirect();

        $flag = InventoryFlag::firstOrFail();
        $this->assertSame('Sour cream', $flag->ingredient->name);
        $this->assertEqualsWithDelta(1.0, (float) $flag->quantity, 0.001);
        // Dairy default shelf life, since the ingredient was created here.
        $this->assertNotNull($flag->expires_on);
    }

    /** A hand-added item must match what recipes refer to, or it steers nothing. */
    public function test_a_hand_added_item_reuses_the_existing_ingredient(): void
    {
        $onion = $this->ingredient('Onion', IngredientCategory::Produce, 6);

        $this->actingAs($this->user)->post(route('pantry.store'), ['name' => 'onions', 'quantity' => 3]);

        $this->assertSame(1, Ingredient::whereRaw('LOWER(name) in (?, ?)', ['onion', 'onions'])->count());
        $this->assertSame($onion->id, InventoryFlag::firstOrFail()->ingredient_id);
    }

    public function test_a_quantity_can_be_corrected(): void
    {
        $onion = $this->ingredient('Onion', IngredientCategory::Produce, 6);
        $flag = $this->inventory->add($onion, 5, 'lb');

        $this->actingAs($this->user)
            ->post(route('pantry.update', $flag), ['quantity' => 2, 'unit' => 'lb', 'expires_on' => '2026-09-20'])
            ->assertRedirect();

        $flag->refresh();
        $this->assertEqualsWithDelta(2.0, (float) $flag->quantity, 0.001);
        $this->assertSame('2026-09-20', $flag->expires_on->toDateString());
    }

    /** Setting the amount to zero says the same thing as "it's gone". */
    public function test_zeroing_the_quantity_clears_the_stock_flag(): void
    {
        $onion = $this->ingredient('Onion', IngredientCategory::Produce, 6);
        $flag = $this->inventory->add($onion, 5, 'lb');

        $this->actingAs($this->user)->post(route('pantry.update', $flag), ['quantity' => 0]);

        $this->assertFalse($flag->fresh()->has_stock);
    }

    public function test_something_marked_gone_can_be_put_back(): void
    {
        $onion = $this->ingredient('Onion', IngredientCategory::Produce, 6);
        $flag = $this->inventory->add($onion, 1, 'lb');
        $this->inventory->markGone($flag);

        $this->actingAs($this->user)->post(route('pantry.restock', $flag))->assertRedirect();

        $this->assertTrue($flag->fresh()->has_stock);
    }

    /** Spec 4.6: in-stock ingredients stay off the grocery list. */
    public function test_pantry_stock_still_keeps_things_off_the_shopping_list(): void
    {
        $recipe = $this->recipeWith('Chilli', ['Onion' => 0.5]);
        $onion = Ingredient::where('name', 'Onion')->firstOrFail();
        $this->inventory->add($onion, 5, 'lb');

        (new MealPlanner)->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $this->assertSame(0, GroceryListItem::count());
    }

    public function test_the_pantry_tab_is_in_the_navigation(): void
    {
        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('Pantry')
            ->assertSee(route('pantry'), false);
    }

    public function test_the_pantry_requires_authentication(): void
    {
        $this->get(route('pantry'))->assertRedirect('/login');
        $this->post(route('pantry.store'), ['name' => 'Onion'])->assertRedirect('/login');
    }
}
