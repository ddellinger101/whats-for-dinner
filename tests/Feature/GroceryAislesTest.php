<?php

namespace Tests\Feature;

use App\Enums\CategoryTag;
use App\Enums\GroceryAisle;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Services\GroceryListBuilder;
use App\Services\MealPlanner;
use App\Support\AisleGuesser;
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
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
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
            ->assertSee('grocery-suggestions')
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
            'rating' => \App\Enums\Rating::JustOk,
        ]);
        Recipe::create([
            'name' => 'Beef Tacos',
            'protein_type' => ProteinType::Beef,
            'rating' => \App\Enums\Rating::ThumbsUp,
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
