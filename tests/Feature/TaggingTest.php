<?php

namespace Tests\Feature;

use App\Enums\CategoryTag;
use App\Enums\ComponentType;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Enums\MealTypeHint;
use App\Enums\ProteinType;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\MealComponent;
use App\Models\Recipe;
use App\Models\SimpleItem;
use App\Models\User;
use App\Services\MealPlanner;
use App\Support\ProteinGuesser;
use App\Support\RecipeTagGuesser;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaggingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
    }

    private function recipeWith(string $name, array $ingredientNames = [], array $links = []): Recipe
    {
        $recipe = Recipe::create([
            'name' => $name,
            'recipe_links' => $links,
            'ingredients_status' => $ingredientNames === []
                ? IngredientsStatus::NotYetAdded
                : IngredientsStatus::ManuallyEntered,
        ]);

        foreach ($ingredientNames as $ingredientName) {
            $ingredient = Ingredient::firstOrCreate(
                ['name' => $ingredientName],
                ['category' => IngredientCategory::Produce, 'shelf_life_days' => 6],
            );
            $recipe->ingredients()->attach($ingredient->id, [
                'id' => (string) Str::uuid(),
                'quantity_per_serving' => 1,
                'unit' => null,
            ]);
        }

        return $recipe->fresh();
    }

    /** @return list<string> */
    private function guessTags(string $name, array $ingredients = [], array $links = []): array
    {
        return collect((new RecipeTagGuesser)->guess($name, $ingredients, $links))
            ->map->value->sort()->values()->all();
    }

    // ------------------------------------------------------------- the enum

    public function test_the_new_cuisine_tags_exist_and_group_separately(): void
    {
        $cuisines = collect(CategoryTag::cuisines())->map->value->all();

        $this->assertSame(
            ['mexican', 'italian', 'spanish', 'asian', 'polynesian', 'american'],
            $cuisines,
        );
        $this->assertContains(CategoryTag::ReadyToEat, CategoryTag::styles());
        $this->assertNotContains(CategoryTag::ReadyToEat, CategoryTag::cuisines());
        $this->assertSame('Ready To Eat', CategoryTag::ReadyToEat->label());
    }

    public function test_other_became_vegetarian(): void
    {
        $this->assertSame('Vegetarian', ProteinType::Vegetarian->label());
        $this->assertNull(ProteinType::tryFrom('other'));
    }

    // ---------------------------------------------------------- tag guessing

    /** A dish gets every tag that fits, not just one. */
    public function test_beef_tacos_are_mexican(): void
    {
        $this->assertContains('mexican', $this->guessTags('Beef Tacos'));
    }

    /** The case that prompted this: it was filed under Other, not Soups. */
    public function test_potato_soup_is_tagged_soup(): void
    {
        $this->assertContains('soup', $this->guessTags('Potato Soup'));
    }

    public function test_a_dish_can_collect_several_tags(): void
    {
        $tags = $this->guessTags('Grilled Chicken Caesar Salad');

        $this->assertContains('salad', $tags);
        $this->assertContains('grilling', $tags);
    }

    public function test_cuisines_are_recognised(): void
    {
        $this->assertContains('italian', $this->guessTags('Chicken Alfredo'));
        $this->assertContains('asian', $this->guessTags('Beef Stir Fry'));
        $this->assertContains('polynesian', $this->guessTags('Hawaiian Pineapple Chicken'));
        $this->assertContains('american', $this->guessTags('Bacon Cheeseburger'));
    }

    /**
     * Spanish and Mexican share a great deal of vocabulary, and tags are
     * additive, so a keyword either owns can quietly tag both. Paella is
     * Spanish alone; chorizo belongs to either and so tags neither.
     */
    public function test_spanish_does_not_bleed_into_mexican(): void
    {
        $paella = $this->guessTags('Chicken and Chorizo Paella');

        $this->assertContains('spanish', $paella);
        $this->assertNotContains('mexican', $paella);

        $this->assertNotContains('spanish', $this->guessTags('Chorizo Tacos'));
        $this->assertContains('spanish', $this->guessTags('Weeknight Rice', ['Saffron', 'Chicken thigh']));
    }

    /**
     * A cuisine's own name must be in its own keyword list. Leaving it out made
     * "Polynesian Chicken w/ Rice" come back untagged, which is absurd.
     */
    public function test_a_cuisine_named_outright_is_always_tagged(): void
    {
        foreach (CategoryTag::cuisines() as $cuisine) {
            $tags = $this->guessTags($cuisine->label().' Chicken');

            $this->assertContains(
                $cuisine->value,
                $tags,
                "naming {$cuisine->label()} outright should tag it",
            );
        }
    }

    /** Ingredients carry the cuisine when the name does not. */
    public function test_ingredients_reveal_a_cuisine_the_name_hides(): void
    {
        $tags = $this->guessTags('Wednesday Skillet', ['Flour tortilla', 'Ground beef', 'Chipotle']);

        $this->assertContains('mexican', $tags);
    }

    /** The link slug is often more descriptive than a shorthand name. */
    public function test_the_link_slug_is_read_too(): void
    {
        $tags = $this->guessTags('Weeknight Dinner', [], ['https://example.com/easy-chicken-enchiladas/']);

        $this->assertContains('mexican', $tags);
    }

    /** Pot pie and shepherd's pie are dinner, not pudding. */
    public function test_savoury_pies_are_not_desserts(): void
    {
        $this->assertNotContains('dessert', $this->guessTags('Chicken Pot Pie'));
        $this->assertNotContains('dessert', $this->guessTags("Shepherd's Pie"));
        $this->assertContains('dessert', $this->guessTags('Apple Pie'));
    }

    /**
     * Ready To Eat means bought needing no ingredients, so a sandwich you
     * assemble at home does not qualify.
     */
    public function test_ready_to_eat_means_bought_not_assembled(): void
    {
        $this->assertContains('ready_to_eat', $this->guessTags('Rotisserie Chicken'));
        $this->assertNotContains('ready_to_eat', $this->guessTags('Turkey Sandwich'));
    }

    // ------------------------------------------------------- protein guessing

    /** The case you caught: filed under Other, but built on ground beef. */
    public function test_burrito_skillet_reads_as_beef_from_its_ingredients(): void
    {
        $protein = (new ProteinGuesser)->guess(
            'Burrito Skillet (protein unspecified)',
            ['Ground beef', 'Flour tortilla', 'Cheddar cheese'],
        );

        $this->assertSame(ProteinType::Beef, $protein);
    }

    public function test_a_dish_with_no_meat_stays_vegetarian(): void
    {
        $protein = (new ProteinGuesser)->guess('Pizza', ['Mozzarella', 'Tomato sauce', 'Dough']);

        $this->assertSame(ProteinType::Vegetarian, $protein);
    }

    public function test_the_name_alone_is_enough(): void
    {
        $this->assertSame(ProteinType::Beef, (new ProteinGuesser)->guess('Beef Tacos'));
        $this->assertSame(ProteinType::Seafood, (new ProteinGuesser)->guess('Grilled Salmon'));
    }

    // -------------------------------------------------------------- command

    public function test_the_command_tags_and_reassigns_protein(): void
    {
        $skillet = $this->recipeWith('Burrito Skillet', ['Ground beef', 'Flour tortilla']);
        $skillet->update(['protein_type' => ProteinType::Vegetarian]);

        $this->artisan('recipes:autotag')->assertSuccessful();

        $skillet->refresh();
        $this->assertSame(ProteinType::Beef, $skillet->protein_type);
        $this->assertTrue($skillet->category_tags->contains(CategoryTag::Mexican));
    }

    /** An explicitly set protein is a fact and must not be overruled. */
    public function test_an_existing_protein_is_never_overwritten(): void
    {
        $recipe = $this->recipeWith('Mystery Bake', ['Ground beef']);
        $recipe->update(['protein_type' => ProteinType::Chicken]);

        $this->artisan('recipes:autotag')->assertSuccessful();

        $this->assertSame(ProteinType::Chicken, $recipe->fresh()->protein_type);
    }

    /** Tags set by hand are decisions; a keyword list should add, not replace. */
    public function test_existing_tags_are_kept(): void
    {
        $recipe = $this->recipeWith('Beef Tacos');
        $recipe->update(['category_tags' => [CategoryTag::Holiday->value]]);

        $this->artisan('recipes:autotag')->assertSuccessful();

        $tags = $recipe->fresh()->category_tags;
        $this->assertTrue($tags->contains(CategoryTag::Holiday));
        $this->assertTrue($tags->contains(CategoryTag::Mexican));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $recipe = $this->recipeWith('Beef Tacos');

        $this->artisan('recipes:autotag --dry-run')->assertSuccessful();

        $this->assertCount(0, $recipe->fresh()->category_tags);
    }

    // ------------------------------------------------- recipe to simple item

    /** Leftovers is something you plan, not a recipe with a protein. */
    public function test_a_recipe_can_become_a_simple_item_keeping_its_place_in_the_plan(): void
    {
        $leftovers = $this->recipeWith('Leftovers');
        (new MealPlanner)->setPrimaryRecipe(Carbon::parse('2026-09-09'), MealSlot::Dinner, $leftovers);

        $this->artisan('recipes:to-item', ['name' => 'Leftovers', '--hint' => 'dinner'])
            ->assertSuccessful();

        $this->assertNull(Recipe::find($leftovers->id));

        $item = SimpleItem::where('name', 'Leftovers')->firstOrFail();
        $this->assertSame(MealTypeHint::Dinner, $item->meal_type_hint);
        // No breakdown prompt: it is not made of anything to shop for.
        $this->assertTrue($item->breakdown_prompted);

        $component = MealComponent::firstOrFail();
        $this->assertSame(ComponentType::SimpleItem, $component->component_type);
        $this->assertSame($item->id, $component->simple_item_id);
        $this->assertSame('Leftovers', $component->displayName());
    }

    // ------------------------------------------------------ create and edit

    public function test_a_recipe_can_be_created_by_hand_with_tags_and_a_link(): void
    {
        $this->actingAs($this->user)
            ->post(route('recipes.store'), [
                'name' => 'Homemade Pizza',
                'protein_type' => ProteinType::Vegetarian->value,
                'meal_type' => 'dinner',
                'base_servings' => 6,
                'category_tags' => [CategoryTag::Italian->value, CategoryTag::ComfortFood->value],
                'links' => "https://example.com/pizza\nnot-a-url",
                'notes' => 'Dough needs an hour.',
            ])
            ->assertRedirect();

        $recipe = Recipe::where('name', 'Homemade Pizza')->firstOrFail();
        $this->assertSame(ProteinType::Vegetarian, $recipe->protein_type);
        $this->assertSame(6, $recipe->base_servings);
        $this->assertTrue($recipe->category_tags->contains(CategoryTag::Italian));
        // The junk line is dropped rather than saved as a broken link.
        $this->assertSame(['https://example.com/pizza'], $recipe->recipe_links);
    }

    /** Spec 3: the keto tag and the keto flag are one fact. */
    public function test_tagging_keto_sets_the_diet_flag(): void
    {
        $this->actingAs($this->user)->post(route('recipes.store'), [
            'name' => 'Keto Bowl',
            'protein_type' => 'chicken',
            'meal_type' => 'dinner',
            'base_servings' => 4,
            'category_tags' => [CategoryTag::Keto->value],
        ]);

        $this->assertTrue(Recipe::where('name', 'Keto Bowl')->firstOrFail()->is_keto);
    }

    public function test_an_existing_recipe_can_be_edited(): void
    {
        $recipe = $this->recipeWith('Pizza');

        $this->actingAs($this->user)
            ->put(route('recipes.update', $recipe), [
                'name' => 'Pizza',
                'protein_type' => ProteinType::Vegetarian->value,
                'meal_type' => 'dinner',
                'base_servings' => 4,
                'category_tags' => [CategoryTag::ReadyToEat->value, CategoryTag::Italian->value],
                'links' => '',
            ])
            ->assertRedirect(route('recipes.show', $recipe));

        $tags = $recipe->fresh()->category_tags;
        $this->assertTrue($tags->contains(CategoryTag::ReadyToEat));
        $this->assertTrue($tags->contains(CategoryTag::Italian));
    }

    public function test_the_new_recipe_form_is_reachable(): void
    {
        $this->actingAs($this->user)->get(route('recipes.create'))
            ->assertOk()
            ->assertSee('New Recipe')
            ->assertSee('Recipe links')
            ->assertSee('Polynesian');
    }

    /** "new" must not be swallowed by the {recipe} route. */
    public function test_the_new_route_is_not_mistaken_for_a_recipe_id(): void
    {
        $this->actingAs($this->user)->get('/recipes/new')->assertOk();
    }

    public function test_creating_a_recipe_requires_authentication(): void
    {
        $this->post(route('recipes.store'), ['name' => 'Sneaky'])->assertRedirect('/login');
        $this->assertSame(0, Recipe::count());
    }

    // ------------------------------------------------------------- deleting

    /**
     * recipe_id nulls on delete rather than cascading, so a bare delete would
     * strand meal components pointing at nothing — blank chips in the week with
     * their groceries still on the list.
     */
    public function test_deleting_a_recipe_clears_it_from_the_plan_and_the_list(): void
    {
        $recipe = $this->recipeWith('Doomed Dish', ['Onion']);
        (new MealPlanner)->setPrimaryRecipe(Carbon::parse('2026-09-09'), MealSlot::Dinner, $recipe);

        $this->assertSame(1, MealComponent::count());
        $this->assertSame(1, GroceryListItem::count());

        $this->actingAs($this->user)
            ->delete(route('recipes.destroy', $recipe))
            ->assertRedirect(route('recipes'));

        $this->assertNull(Recipe::find($recipe->id));
        $this->assertSame(0, MealComponent::count());
        $this->assertSame(0, GroceryListItem::count());
        // The ingredient itself survives for other recipes.
        $this->assertSame(1, Ingredient::where('name', 'Onion')->count());
    }

    /** Shopping already done is a decision, not stale data. */
    public function test_deleting_a_recipe_leaves_bought_groceries_alone(): void
    {
        $recipe = $this->recipeWith('Doomed Dish', ['Onion']);
        (new MealPlanner)->setPrimaryRecipe(Carbon::parse('2026-09-09'), MealSlot::Dinner, $recipe);

        GroceryListItem::firstOrFail()->markPurchased();

        $this->actingAs($this->user)->delete(route('recipes.destroy', $recipe));

        $this->assertSame(1, GroceryListItem::count());
    }

    public function test_the_edit_screen_offers_deletion(): void
    {
        $recipe = $this->recipeWith('Doomed Dish');

        $this->actingAs($this->user)->get(route('recipes.edit', $recipe))
            ->assertOk()
            ->assertSee('Delete this recipe');

        // Not offered before the recipe exists.
        $this->actingAs($this->user)->get(route('recipes.create'))
            ->assertOk()
            ->assertDontSee('Delete this recipe');
    }

    public function test_deleting_requires_authentication(): void
    {
        $recipe = $this->recipeWith('Safe Dish');

        $this->delete(route('recipes.destroy', $recipe))->assertRedirect('/login');
        $this->assertNotNull(Recipe::find($recipe->id));
    }
}
