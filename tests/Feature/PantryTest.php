<?php

namespace Tests\Feature;

use App\Enums\GroceryAisle;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Enums\Rating;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\Recipe;
use App\Models\User;
use App\Services\GroceryListBuilder;
use App\Services\InventoryService;
use App\Services\MealPlanner;
use App\Services\RecipeSuggestionRanker;
use Database\Seeders\HouseholdSeeder;
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
        $this->seed(HouseholdSeeder::class);
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

    /**
     * Hand-typed lines carry no ingredient_id and are the majority of a real
     * list, so they have to stock the pantry too — otherwise it stays
     * permanently empty for anyone who types their shopping.
     */
    public function test_a_hand_typed_item_still_stocks_the_pantry(): void
    {
        $item = (new GroceryListBuilder)->addManual('Half & Half');

        $this->actingAs($this->user)->post(route('grocery.toggle', $item))->assertRedirect();

        $flag = InventoryFlag::firstOrFail();
        $this->assertSame('Half & Half', $flag->ingredient->name);
        $this->assertTrue($flag->has_stock);

        // The line is linked back, so re-buying updates the same pantry row
        // rather than creating a second one.
        $this->assertSame($flag->ingredient_id, $item->fresh()->ingredient_id);
    }

    /** Resolution is shared, so a typed item matches what recipes refer to. */
    public function test_a_typed_item_matches_the_ingredient_recipes_use(): void
    {
        $onion = $this->ingredient('Onion', IngredientCategory::Produce, 6);
        $item = (new GroceryListBuilder)->addManual('onions');

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));

        $this->assertSame($onion->id, InventoryFlag::firstOrFail()->ingredient_id);
        $this->assertSame(1, InventoryFlag::count());
    }

    /** Shopping bought before the pantry existed can be read in afterwards. */
    public function test_the_backfill_reads_in_already_purchased_shopping(): void
    {
        $bread = (new GroceryListBuilder)->addManual('Bread');
        $sugar = (new GroceryListBuilder)->addManual('Sugar');
        // Marked bought directly, bypassing the controller hook.
        $bread->update(['status' => 'purchased']);
        $sugar->update(['status' => 'purchased']);

        $this->assertSame(0, InventoryFlag::count());

        $this->artisan('pantry:backfill')->assertSuccessful();

        $this->assertSame(2, InventoryFlag::count());
        $this->assertEqualsWithDelta(
            2,
            InventoryFlag::whereHas('ingredient', fn ($q) => $q->whereIn('name', ['Bread', 'Sugar']))->count(),
            0,
        );
    }

    /** Re-running must not keep stacking quantity onto what is already counted. */
    public function test_the_backfill_does_not_double_count(): void
    {
        $bread = (new GroceryListBuilder)->addManual('Bread');
        $bread->update(['status' => 'purchased']);

        $this->artisan('pantry:backfill')->assertSuccessful();
        $this->artisan('pantry:backfill')->assertSuccessful();

        $this->assertSame(1, InventoryFlag::count());
    }

    /** Cleared lines are still shopping that happened. */
    public function test_the_backfill_can_read_cleared_lines(): void
    {
        $bread = (new GroceryListBuilder)->addManual('Bread');
        $bread->update(['status' => 'purchased']);
        $bread->delete();

        $this->artisan('pantry:backfill')->assertSuccessful();
        $this->assertSame(0, InventoryFlag::count());

        $this->artisan('pantry:backfill --include-cleared')->assertSuccessful();
        $this->assertSame(1, InventoryFlag::count());
    }

    /** The suggestion data has to reach the page for the autofill to work. */
    public function test_the_autofill_data_is_embedded_for_the_client(): void
    {
        (new GroceryListBuilder)->addManual('Rotisserie chicken');

        $this->actingAs($this->user)->get(route('grocery'))
            ->assertOk()
            ->assertSee('grocery-suggestion-data')
            ->assertSee('Rotisserie chicken')
            // Built by hand because iOS Safari does not render a datalist.
            ->assertDontSee('<datalist', false);
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
        Recipe::create(['name' => 'Plain Roast', 'rating' => Rating::ThumbsUp]);

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

    // ------------------------------------------- acting on what is going off

    /**
     * Three, and no more: find a recipe, add to the list, edit. The fourth was
     * an x doing exactly what "It's gone" in the edit menu already did — two
     * targets and their gaps for one action, on the screen where names run
     * longest.
     */
    public function test_a_pantry_row_carries_three_tap_targets(): void
    {
        $flag = $this->inventory->add(
            $this->ingredient('Boneless skinless chicken breasts', IngredientCategory::PantryDry, 300),
            2,
            'lb',
        );

        $html = $this->actingAs($this->user)->get(route('pantry'))->assertOk()->getContent();
        $row = Str::between($html, '<li class="px-3 py-2">', '</li>');

        $this->assertSame(3, substr_count($row, 'size-tap'), 'search, grocery, edit — nothing else');

        // Marking it gone is still one menu away, not gone itself.
        $this->assertStringContainsString(route('pantry.gone', $flag), $row);
        $this->assertStringContainsString('It&rsquo;s gone', $row);
    }

    /**
     * Read in the order things go off, not in the order of the alphabet.
     * Dairy turns before the condiments do, so it is what you want at the top;
     * the alphabet put bakery and condiments above it for no reason anyone
     * cares about.
     */
    public function test_categories_are_ordered_by_how_soon_they_go_off(): void
    {
        // All beyond the four-day at-risk window, so they stay in their
        // categories rather than being lifted into "use these up".
        $this->inventory->add($this->ingredient('Ketchup', IngredientCategory::Condiment, 90), 1, 'bottle');
        $this->inventory->add($this->ingredient('Rice', IngredientCategory::PantryDry, 300), 2, 'cup');
        $this->inventory->add($this->ingredient('Milk', IngredientCategory::Dairy, 9), 1, 'gal');
        $this->inventory->add($this->ingredient('Yoghurt', IngredientCategory::Dairy, 7), 2, 'cup');

        $order = $this->actingAs($this->user)->get(route('pantry'))
            ->assertOk()
            ->viewData('byCategory')
            ->keys()
            ->all();

        $this->assertSame(['dairy', 'condiment', 'pantry_dry'], $order);
    }

    /**
     * Not knowing when something expires is not the same as it expiring today,
     * so it sorts last rather than being lifted above the milk.
     */
    public function test_a_category_with_no_dates_sorts_last(): void
    {
        $undated = $this->inventory->add($this->ingredient('Bay leaves', IngredientCategory::PantryDry, 300), null, null);
        $undated->update(['expires_on' => null]);
        $this->inventory->add($this->ingredient('Ketchup', IngredientCategory::Condiment, 90), 1, 'bottle');

        $order = $this->actingAs($this->user)->get(route('pantry'))
            ->assertOk()
            ->viewData('byCategory')
            ->keys()
            ->all();

        $this->assertSame(['condiment', 'pantry_dry'], $order);
    }

    /**
     * Something running out is often something bought every week, so the row
     * that tells you it is going off is where the list should be one tap away.
     */
    public function test_a_use_it_up_row_offers_a_quick_add_to_the_grocery_list(): void
    {
        $sourCream = $this->ingredient('Sour cream', IngredientCategory::Dairy, 2);
        $flag = $this->inventory->add($sourCream, 1, 'cup');

        $this->actingAs($this->user)->get(route('pantry'))
            ->assertOk()
            ->assertSee(route('pantry.grocery', $flag), false);

        $this->actingAs($this->user)
            ->post(route('pantry.grocery', $flag))
            ->assertRedirect();

        $line = GroceryListItem::needed()->firstOrFail();
        $this->assertSame('Sour cream', $line->item_name);
        // Linked to the ingredient, so buying it restocks this same pantry row.
        $this->assertSame($sourCream->id, $line->ingredient_id);
        $this->assertSame(GroceryAisle::Dairy, $line->aisle);
    }

    /** Tapping twice must not make two lines. */
    public function test_adding_something_already_on_the_list_does_not_duplicate_it(): void
    {
        $flag = $this->inventory->add($this->ingredient('Sour cream', IngredientCategory::Dairy, 2), 1, 'cup');

        $this->actingAs($this->user)->post(route('pantry.grocery', $flag));
        $this->actingAs($this->user)->post(route('pantry.grocery', $flag))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'already on the grocery list'));

        $this->assertSame(1, GroceryListItem::needed()->count());
    }

    /**
     * On every row, not only the ones going off. Waiting until something is
     * nearly out is how it ends up wanted on a night nobody is shopping.
     */
    public function test_every_row_can_add_to_the_grocery_list_not_just_the_urgent_ones(): void
    {
        $rice = $this->inventory->add($this->ingredient('Rice', IngredientCategory::PantryDry, 300), 2, 'cup');
        $cream = $this->inventory->add($this->ingredient('Sour cream', IngredientCategory::Dairy, 2), 1, 'cup');

        $html = $this->actingAs($this->user)->get(route('pantry'))->assertOk()->getContent();

        // One is months off, the other is in the use-these-up section.
        $this->assertStringContainsString(route('pantry.grocery', $rice), $html);
        $this->assertStringContainsString(route('pantry.grocery', $cream), $html);

        $this->actingAs($this->user)->post(route('pantry.grocery', $rice))->assertRedirect();
        $this->assertSame('Rice', GroceryListItem::needed()->firstOrFail()->item_name);
    }

    /**
     * A section of its own, and never in the use-these-up list: a bottle of gin
     * outlasts any planning horizon.
     */
    public function test_the_bar_is_its_own_pantry_section_and_does_not_expire(): void
    {
        $gin = $this->ingredient('Gin', IngredientCategory::Bar, 365);
        $this->inventory->add($gin, 1, 'bottle');

        $this->assertFalse($gin->isPerishable());

        $response = $this->actingAs($this->user)->get(route('pantry'))->assertOk();

        $this->assertContains('bar', $response->viewData('byCategory')->keys()->all());
        $response->assertSee('Bar')->assertSee('Gin');

        $this->assertNotContains(
            $gin->id,
            $this->inventory->atRiskIngredientIds(Carbon::today())->all(),
        );
    }

    /** Knowing something is going off is only useful if you can act on it. */
    public function test_each_pantry_item_offers_a_recipe_search(): void
    {
        $sourCream = $this->ingredient('Sour cream', IngredientCategory::Dairy, 2);
        $this->inventory->add($sourCream, 1, 'cup');

        $this->actingAs($this->user)->get(route('pantry'))
            ->assertOk()
            ->assertSee(route('recipes', ['ingredient' => $sourCream->id]), false);
    }

    /**
     * Matched on the ingredient row rather than on its name, so the search
     * lines up with what the pantry and the use-by windows are keyed on.
     */
    public function test_recipes_can_be_filtered_to_one_ingredient(): void
    {
        $usesIt = $this->recipeWith('Sour Cream Bake', ['Sour cream' => 0.25]);
        $this->recipeWith('Plain Roast', ['Beef' => 0.5]);

        $sourCream = Ingredient::where('name', 'Sour cream')->firstOrFail();

        $this->actingAs($this->user)->get(route('recipes', ['ingredient' => $sourCream->id]))
            ->assertOk()
            ->assertSee('Recipes using')
            ->assertSee($usesIt->name)
            ->assertDontSee('Plain Roast');
    }

    /** Narrowing further must not drop the ingredient you came here for. */
    public function test_the_ingredient_filter_survives_another_filter(): void
    {
        $sourCream = $this->ingredient('Sour cream', IngredientCategory::Dairy, 2);

        $this->actingAs($this->user)->get(route('recipes', ['ingredient' => $sourCream->id]))
            ->assertOk()
            // Every protein chip carries the ingredient along.
            ->assertSee('ingredient='.$sourCream->id, false);
    }

    /** Owning nothing that uses it is exactly when searching the web helps. */
    public function test_an_ingredient_no_recipe_uses_offers_the_web_search(): void
    {
        $orphan = $this->ingredient('Tamarind paste', IngredientCategory::Condiment, 90);

        $this->actingAs($this->user)->get(route('recipes', ['ingredient' => $orphan->id]))
            ->assertOk()
            ->assertSee('None of your recipes use Tamarind paste')
            ->assertSee(route('discover', ['q' => 'Tamarind paste']), false);
    }

    /** A stale or hand-typed id must not blow the page up. */
    public function test_an_unknown_ingredient_filter_is_ignored(): void
    {
        $this->recipeWith('Plain Roast', ['Beef' => 0.5]);

        $this->actingAs($this->user)
            ->get(route('recipes', ['ingredient' => '01a00000-0000-7000-8000-000000000000']))
            ->assertOk()
            ->assertSee('Plain Roast');
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
