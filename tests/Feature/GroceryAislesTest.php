<?php

namespace Tests\Feature;

use App\Enums\CategoryTag;
use App\Enums\GroceryAisle;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Enums\Rating;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Services\GroceryListBuilder;
use App\Services\MealPlanner;
use App\Support\AisleGuesser;
use App\Support\BarKeywords;
use App\Support\IngredientCategoryGuesser;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class GroceryAislesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const WEDNESDAY = '2026-09-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
    }

    // ------------------------------------------------------ aisle guessing

    /** Protein is one shelf-life class but two aisles. */
    public function test_meat_and_seafood_are_separated(): void
    {
        $guesser = new AisleGuesser;

        $this->assertSame(GroceryAisle::Meat, $guesser->guess('Ground beef'));
        $this->assertSame(GroceryAisle::Meat, $guesser->guess('Chicken thighs'));
        $this->assertSame(GroceryAisle::Seafood, $guesser->guess('Salmon fillet'));
        $this->assertSame(GroceryAisle::Seafood, $guesser->guess('Large shrimp'));
    }

    /** Bread is dry pantry goods by shelf life, but bakery by geography. */
    public function test_bakery_items_leave_the_pantry(): void
    {
        $guesser = new AisleGuesser;

        $this->assertSame(GroceryAisle::Bakery, $guesser->guess('Flour tortillas'));
        $this->assertSame(GroceryAisle::Bakery, $guesser->guess('Hamburger buns'));
        // Flour itself is still pantry.
        Ingredient::create([
            'name' => 'All-purpose flour',
            'category' => IngredientCategory::PantryDry,
            'shelf_life_days' => 365,
        ]);
        $this->assertSame(GroceryAisle::Pantry, $guesser->guess('All-purpose flour'));
    }

    public function test_the_ingredient_category_fills_the_gaps(): void
    {
        Ingredient::create([
            'name' => 'Sour cream',
            'category' => IngredientCategory::Dairy,
            'shelf_life_days' => 12,
        ]);

        $this->assertSame(GroceryAisle::Dairy, (new AisleGuesser)->guess('Sour cream'));
    }

    /** Nothing known: Other is a visible prompt to tag it, not a silent guess. */
    public function test_an_unknown_item_lands_in_other(): void
    {
        $this->assertSame(GroceryAisle::Other, (new AisleGuesser)->guess('Birthday candles'));
    }

    /**
     * Prepared food must beat its own ingredient's aisle. "Rotisserie chicken"
     * contains "chicken", and filing it under raw meat sends you to the wrong
     * end of the shop.
     */
    public function test_prepared_food_outranks_the_raw_ingredient_it_names(): void
    {
        $guesser = new AisleGuesser;

        $this->assertSame(GroceryAisle::ReadyToEat, $guesser->guess('Rotisserie chicken'));
        $this->assertSame(GroceryAisle::ReadyToEat, $guesser->guess('Deli tray'));
        // The raw form is unaffected.
        $this->assertSame(GroceryAisle::Meat, $guesser->guess('Chicken breast'));
    }

    /**
     * A bottle of fish sauce does not come from the fish counter, a carton of
     * chicken stock does not come from the butcher, and steak sauce is not
     * steak. All three are named after the animal they go with.
     */
    public function test_things_named_after_meat_and_fish_are_still_pantry(): void
    {
        $guesser = new AisleGuesser;

        foreach (['Fish sauce', 'Oyster sauce', 'Steak sauce', 'Chicken broth',
            'Beef stock', 'Chicken bouillon', 'Turkey gravy'] as $item) {
            $this->assertSame(GroceryAisle::Pantry, $guesser->guess($item), $item);
        }

        // The animals themselves are unaffected.
        $this->assertSame(GroceryAisle::Seafood, $guesser->guess('Salmon fillet'));
        $this->assertSame(GroceryAisle::Meat, $guesser->guess('Ribeye steak'));
        $this->assertSame(GroceryAisle::Meat, $guesser->guess('Chicken breast'));
    }

    /**
     * The bar is its own trip, and almost everything on it is claimed by a
     * later rule: simple syrup by "syrup", club soda by "soda", the cherries
     * by the jar rule.
     */
    public function test_the_bar_has_an_aisle_of_its_own(): void
    {
        $guesser = new AisleGuesser;

        foreach (['Gin', 'Bourbon', 'Sweet vermouth', 'Angostura bitters', 'Simple syrup',
            'Cocktail cherries', 'Cocktail onions', 'Tonic water', 'Club soda', 'Triple sec'] as $item) {
            $this->assertSame(GroceryAisle::Bar, $guesser->guess($item), $item);
        }

        // Cocktail sauce is for prawns, and beer is still a drink.
        $this->assertSame(GroceryAisle::Pantry, $guesser->guess('Cocktail sauce'));
        $this->assertSame(GroceryAisle::Pantry, $guesser->guess('Sherry vinegar'));
        $this->assertSame(GroceryAisle::Drinks, $guesser->guess('Beer'));
        $this->assertSame(GroceryAisle::Drinks, $guesser->guess('Red wine'));
    }

    /**
     * The two guessers read the same list, or they disagree about the same
     * bottle: the aisle rules run before the category is consulted, so each
     * needs the bar names, and two copies would drift.
     */
    public function test_both_guessers_agree_about_what_is_bar_stock(): void
    {
        $aisles = new AisleGuesser;
        $categories = new IngredientCategoryGuesser;

        foreach (BarKeywords::all() as $keyword) {
            $this->assertSame(
                IngredientCategory::Bar,
                $categories->guess($keyword),
                "{$keyword} should be bar stock",
            );
            $this->assertSame(
                GroceryAisle::Bar,
                $aisles->guess($keyword, null, false),
                "{$keyword} should be on the bar aisle",
            );
        }
    }

    /** "Amaro" does not catch amaretto: the word ends differently. */
    public function test_liqueurs_named_in_full_reach_the_bar(): void
    {
        $guesser = new AisleGuesser;

        foreach (['Amaretto', 'Kahlua', 'Irish cream', 'Creme de menthe', 'Coconut rum',
            'Citrus vodka', 'Dark rum', 'Angostura bitters'] as $item) {
            $this->assertSame(GroceryAisle::Bar, $guesser->guess($item), $item);
        }

        // Irish cream is not cream, but heavy cream still is.
        $this->assertSame(GroceryAisle::Dairy, $guesser->guess('Heavy cream'));
    }

    /** It has to reach the list, not just the guesser. */
    public function test_the_bar_gets_its_own_section_on_the_list(): void
    {
        (new GroceryListBuilder)->addManual('Bourbon');
        (new GroceryListBuilder)->addManual('Bananas');

        $this->actingAs($this->user)->get(route('grocery'))
            ->assertOk()
            ->assertSee('Bar')
            ->assertSee('Bourbon');
    }

    public function test_drinks_have_an_aisle_of_their_own(): void
    {
        $guesser = new AisleGuesser;

        $this->assertSame(GroceryAisle::Drinks, $guesser->guess('Hard cider'));
        $this->assertSame(GroceryAisle::Drinks, $guesser->guess('Orange juice'));
        $this->assertSame(GroceryAisle::Drinks, $guesser->guess('Ground coffee'));
    }

    /** A correction teaches the list, so the same item never re-sorts twice. */
    public function test_the_guesser_remembers_a_previous_correction(): void
    {
        $this->assertSame(GroceryAisle::Other, (new AisleGuesser)->guess('Birthday candles'));

        GroceryListItem::create([
            'item_name' => 'Birthday candles',
            'source' => 'manual',
            'status' => 'needed',
            'added_date' => self::WEDNESDAY,
            'aisle' => GroceryAisle::Pantry,
        ]);

        $this->assertSame(GroceryAisle::Pantry, (new AisleGuesser)->guess('birthday candles'));
    }

    /**
     * A re-derive must ignore the memory, or improved rules could never correct
     * an earlier mistake — it would only read back its own previous answer.
     */
    public function test_a_forced_reguess_ignores_the_remembered_answer(): void
    {
        GroceryListItem::create([
            'item_name' => 'Rotisserie chicken',
            'source' => 'manual',
            'status' => 'needed',
            'added_date' => self::WEDNESDAY,
            // Filed wrongly by an earlier version of the rules.
            'aisle' => GroceryAisle::Meat,
        ]);

        $guesser = new AisleGuesser;

        $this->assertSame(GroceryAisle::Meat, $guesser->guess('Rotisserie chicken'));
        $this->assertSame(
            GroceryAisle::ReadyToEat,
            $guesser->guess('Rotisserie chicken', null, useMemory: false),
        );

        $this->artisan('grocery:aisles --all')->assertSuccessful();

        $this->assertSame(GroceryAisle::ReadyToEat, GroceryListItem::firstOrFail()->aisle);
    }

    /** Tidying the list must not erase what it learned. */
    public function test_memory_survives_clearing_the_list(): void
    {
        $item = GroceryListItem::create([
            'item_name' => 'Birthday candles',
            'source' => 'manual',
            'status' => 'purchased',
            'added_date' => self::WEDNESDAY,
            'aisle' => GroceryAisle::Pantry,
        ]);

        $item->delete();

        $this->assertSame(0, GroceryListItem::count());
        $this->assertSame(GroceryAisle::Pantry, (new AisleGuesser)->guess('Birthday candles'));
    }

    // ------------------------------------------------------------ the list

    private function recipeWith(string $name, array $ingredients): Recipe
    {
        $recipe = Recipe::create([
            'name' => $name,
            'protein_type' => ProteinType::Beef,
            'base_servings' => 4,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        foreach ($ingredients as $ingredientName => $category) {
            $ingredient = Ingredient::firstOrCreate(
                ['name' => $ingredientName],
                ['category' => $category, 'shelf_life_days' => $category->defaultShelfLifeDays()],
            );
            $recipe->ingredients()->attach($ingredient->id, [
                'id' => (string) Str::uuid(),
                'quantity_per_serving' => 0.25,
                'unit' => 'cup',
            ]);
        }

        return $recipe->fresh();
    }

    public function test_the_list_is_grouped_into_aisle_sections(): void
    {
        $recipe = $this->recipeWith('Tacos', [
            'Ground beef' => IngredientCategory::Protein,
            'Flour tortillas' => IngredientCategory::PantryDry,
            'Sour cream' => IngredientCategory::Dairy,
            'Onion' => IngredientCategory::Produce,
        ]);

        (new MealPlanner)->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $response = $this->actingAs($this->user)->get(route('grocery'))->assertOk();

        // Sections appear in the order a shop is walked.
        $response->assertSeeInOrder(['Produce', 'Bakery', 'Meat', 'Dairy']);

        $aisles = GroceryListItem::pluck('aisle', 'item_name');
        $this->assertSame(GroceryAisle::Meat, $aisles['Ground beef']);
        $this->assertSame(GroceryAisle::Bakery, $aisles['Flour tortillas']);
        $this->assertSame(GroceryAisle::Dairy, $aisles['Sour cream']);
        $this->assertSame(GroceryAisle::Produce, $aisles['Onion']);
    }

    /**
     * Empty sections are dropped before the view sees them.
     *
     * Asserted on the view data rather than the HTML: every aisle name also
     * appears in the "set the aisle" picker, so searching the markup for
     * "Seafood" would find it whether or not the section was rendered.
     */
    public function test_aisles_with_nothing_in_them_are_hidden(): void
    {
        (new GroceryListBuilder)->addManual('Bananas');

        $sections = $this->actingAs($this->user)->get(route('grocery'))
            ->assertOk()
            ->viewData('aisles');

        $this->assertSame(
            ['Produce'],
            $sections->map(fn (array $section) => $section['aisle']->label())->all(),
        );
    }

    /** Bought items stay in place, crossed off, rather than jumping sections. */
    public function test_a_bought_item_stays_in_its_aisle(): void
    {
        $item = (new GroceryListBuilder)->addManual('Bananas');

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));

        $this->actingAs($this->user)->get(route('grocery'))
            ->assertOk()
            ->assertSee('Produce')
            ->assertSee('line-through', false);
    }

    /**
     * The aisle menu is absolutely positioned, so an ancestor with
     * overflow-hidden clips it out of sight no matter what z-index it carries —
     * which is exactly what happened, leaving the button apparently dead.
     *
     * Asserting on a class name is blunt, but this is a rendering bug no
     * behavioural test can see, and the clipping container is the cause.
     */
    /**
     * A row had four 44px tap targets across it — the tick, then aisle,
     * already-have and remove — leaving a third of a phone's width for the
     * name you are actually scanning for. Everything but the tick is now
     * behind one menu.
     */
    public function test_a_row_carries_only_two_tap_targets(): void
    {
        $item = (new GroceryListBuilder)->addManual('Boneless skinless chicken breasts');

        $html = $this->actingAs($this->user)->get(route('grocery'))->assertOk()->getContent();
        $row = Str::between($html, '<li class="flex items-center', '</li>');

        $this->assertSame(2, substr_count($row, 'size-tap'), 'the tick and the menu, nothing else');

        // The actions are still all reachable, just one tap further in.
        $this->assertStringContainsString(route('grocery.destroy', $item), $row);
        $this->assertStringContainsString(route('grocery.aisle', $item), $row);
    }

    /** The name gets the first line; the amount moves down to join the meta. */
    public function test_the_name_has_the_first_line_to_itself(): void
    {
        $item = (new GroceryListBuilder)->addManual('Heavy cream', 2.0, 'cup');

        $html = $this->actingAs($this->user)->get(route('grocery'))->assertOk()->getContent();
        $row = Str::between($html, '<li class="flex items-center', '</li>');

        $name = strpos($row, 'Heavy cream');
        $amount = strpos($row, 'Change how much');

        $this->assertNotFalse($name);
        $this->assertNotFalse($amount);
        $this->assertLessThan($amount, $name, 'the name should come before the amount, not after it');
        $this->assertStringContainsString(route('grocery.quantity', $item), $row);
    }

    /**
     * "for Chicken Piccata and Chicken C…" tells you less than the space it
     * takes, so the detail line can be opened where it stands.
     */
    public function test_a_cut_off_detail_line_can_be_expanded(): void
    {
        $recipes = ['Chicken Piccata', 'Chicken Caesar Salad'];

        foreach ($recipes as $index => $name) {
            $recipe = $this->recipeWith($name, ['Chicken breasts' => IngredientCategory::Protein]);
            (new MealPlanner)->setPrimaryRecipe(
                Carbon::parse(self::WEDNESDAY)->addDays($index),
                MealSlot::Dinner,
                $recipe,
            );
        }

        $html = $this->actingAs($this->user)->get(route('grocery'))->assertOk()->getContent();
        $row = Str::between($html, '<li class="flex items-center', '</li>');

        // The whole label is in the markup; the truncation is presentational,
        // so opening it needs no round trip.
        $this->assertStringContainsString('for Chicken Piccata and Chicken Caesar Salad', $row);
        $this->assertStringContainsString('data-reason', $row);
        $this->assertStringContainsString('data-reason-toggle', $row);
        // Wraps once open rather than staying on one clipped line.
        $this->assertStringContainsString('group-open:whitespace-normal', $row);
    }

    public function test_the_aisle_menu_is_not_inside_a_clipping_container(): void
    {
        (new GroceryListBuilder)->addManual('Bananas');

        $html = $this->actingAs($this->user)->get(route('grocery'))->assertOk()->getContent();

        // The aisle list lives in the row's one overflow menu now, rather than
        // behind an icon of its own.
        $this->assertStringContainsString('More for Bananas', $html,
            'the row menu holding the aisles should be on the page');
        $this->assertStringContainsString('Ready To Eat', $html,
            'the aisle choices should be inside it');

        $this->assertStringNotContainsString(
            'divide-ink-100 overflow-hidden rounded-2xl border border-ink-200 bg-white shadow-sm',
            $html,
            'the list holding the aisle menu must not clip its overflow',
        );
    }

    // ---------------------------------------------------------- manual add

    public function test_a_manual_item_can_be_filed_by_hand(): void
    {
        $this->actingAs($this->user)
            ->post(route('grocery.store'), [
                'item_name' => 'Paper plates',
                'aisle' => GroceryAisle::Pantry->value,
            ])
            ->assertRedirect();

        $this->assertSame(GroceryAisle::Pantry, GroceryListItem::firstOrFail()->aisle);
    }

    public function test_an_items_aisle_can_be_corrected_afterwards(): void
    {
        $item = (new GroceryListBuilder)->addManual('Birthday candles');
        $this->assertSame(GroceryAisle::Other, $item->aisle);

        $this->actingAs($this->user)
            ->post(route('grocery.aisle', $item), ['aisle' => GroceryAisle::Pantry->value])
            ->assertRedirect();

        $this->assertSame(GroceryAisle::Pantry, $item->fresh()->aisle);

        // And the correction is remembered for next time.
        $this->assertSame(GroceryAisle::Pantry, (new AisleGuesser)->guess('Birthday candles'));
    }

    // ------------------------------------------------------------ autofill

    public function test_past_entries_become_autofill_suggestions(): void
    {
        (new GroceryListBuilder)->addManual('Rotisserie chicken');
        Ingredient::create([
            'name' => 'Smoked paprika',
            'category' => IngredientCategory::PantryDry,
            'shelf_life_days' => 365,
        ]);

        $this->actingAs($this->user)->get(route('grocery'))
            ->assertOk()
            ->assertSee('grocery-suggestion-data')
            ->assertSee('Rotisserie chicken')
            ->assertSee('Smoked paprika');
    }

    /** Cleared shopping is exactly what you least want to retype. */
    public function test_cleared_items_still_offer_themselves_as_autofill(): void
    {
        $item = (new GroceryListBuilder)->addManual('Pickled onions');
        $item->markPurchased();

        $this->actingAs($this->user)->post(route('grocery.clear'))->assertRedirect();
        $this->assertSame(0, GroceryListItem::count());

        $this->actingAs($this->user)->get(route('grocery'))
            ->assertOk()
            ->assertSee('Pickled onions');
    }

    // ------------------------------------------------- picker search/filter

    public function test_the_dinner_picker_can_be_searched(): void
    {
        Recipe::create(['name' => 'Beef Tacos', 'protein_type' => ProteinType::Beef]);
        Recipe::create(['name' => 'Chicken Alfredo', 'protein_type' => ProteinType::Chicken]);

        $this->actingAs($this->user)
            ->get(route('plan.picker', [
                'date' => self::WEDNESDAY, 'slot' => 'dinner', 'primary' => 1, 'q' => 'taco',
            ]))
            ->assertOk()
            ->assertSee('Beef Tacos')
            ->assertDontSee('Chicken Alfredo');
    }

    public function test_the_dinner_picker_can_be_filtered_by_protein(): void
    {
        Recipe::create(['name' => 'Beef Tacos', 'protein_type' => ProteinType::Beef]);
        Recipe::create(['name' => 'Chicken Alfredo', 'protein_type' => ProteinType::Chicken]);

        $this->actingAs($this->user)
            ->get(route('plan.picker', [
                'date' => self::WEDNESDAY, 'slot' => 'dinner', 'primary' => 1, 'protein' => 'beef',
            ]))
            ->assertOk()
            ->assertSee('Beef Tacos')
            ->assertDontSee('Chicken Alfredo');
    }

    public function test_the_dinner_picker_can_be_filtered_by_tag(): void
    {
        Recipe::create([
            'name' => 'Beef Tacos',
            'protein_type' => ProteinType::Beef,
            'category_tags' => [CategoryTag::Mexican->value],
        ]);
        Recipe::create([
            'name' => 'Chicken Alfredo',
            'protein_type' => ProteinType::Chicken,
            'category_tags' => [CategoryTag::Italian->value],
        ]);

        $this->actingAs($this->user)
            ->get(route('plan.picker', [
                'date' => self::WEDNESDAY, 'slot' => 'dinner', 'primary' => 1, 'tag' => 'mexican',
            ]))
            ->assertOk()
            ->assertSee('Beef Tacos')
            ->assertDontSee('Chicken Alfredo');
    }

    /** Narrowing must not lose the spec 4.2 ordering within what remains. */
    public function test_filtering_preserves_the_suggestion_ranking(): void
    {
        Recipe::create([
            'name' => 'Beef Stew',
            'protein_type' => ProteinType::Beef,
            'rating' => Rating::JustOk,
        ]);
        Recipe::create([
            'name' => 'Beef Tacos',
            'protein_type' => ProteinType::Beef,
            'rating' => Rating::ThumbsUp,
        ]);

        $this->actingAs($this->user)
            ->get(route('plan.picker', [
                'date' => self::WEDNESDAY, 'slot' => 'dinner', 'primary' => 1, 'protein' => 'beef',
            ]))
            ->assertOk()
            // Thumbs-up still outranks just-OK inside the filtered subset.
            ->assertSeeInOrder(['Beef Tacos', 'Beef Stew']);
    }

    public function test_combined_filters_that_match_nothing_offer_a_way_back(): void
    {
        Recipe::create(['name' => 'Beef Tacos', 'protein_type' => ProteinType::Beef]);

        $this->actingAs($this->user)
            ->get(route('plan.picker', [
                'date' => self::WEDNESDAY, 'slot' => 'dinner', 'primary' => 1,
                'protein' => 'seafood', 'q' => 'taco',
            ]))
            ->assertOk()
            ->assertSee('No recipes match')
            ->assertSee('Clear the filters');
    }
}
