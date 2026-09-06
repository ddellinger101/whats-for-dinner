<?php

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\MealComponent;
use App\Models\Recipe;
use App\Models\User;
use App\Services\IngredientResolver;
use App\Services\InventoryService;
use App\Services\MealPlanner;
use App\Support\IngredientLine;
use App\Support\PantryStaples;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The spice rack. Forty jars the household always has, which no recipe should
 * be able to put on the grocery list — while a recipe asking for fresh herbs
 * still can.
 */
class PantryStaplesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
    }

    private function resolve(string $name): Ingredient
    {
        return (new IngredientResolver)->resolve($name);
    }

    // ------------------------------------------------------------- matching

    /** One jar, however the recipe spells it. */
    public function test_every_spelling_of_a_jar_resolves_to_one_ingredient(): void
    {
        $ids = collect(['ground cumin', 'Cumin', 'CUMIN GROUND', 'cumin ground'])
            ->map(fn (string $name) => $this->resolve($name)->id)
            ->unique();

        $this->assertCount(1, $ids, 'each spelling should land on the same row');
        $this->assertSame('Cumin ground', Ingredient::find($ids->first())->name);
        $this->assertTrue(Ingredient::find($ids->first())->isStaple());
    }

    /**
     * The rule the household asked for outright: a jar of dried thyme does not
     * satisfy a recipe wanting fresh, so that line still gets bought.
     */
    public function test_fresh_is_never_a_staple(): void
    {
        foreach (['Fresh thyme', 'fresh basil leaves', 'Thyme, fresh', 'FRESH ROSEMARY'] as $name) {
            $ingredient = $this->resolve($name);

            $this->assertFalse($ingredient->isStaple(), "{$name} should not be a staple");
        }

        // And it is a different row from the jar, not a renamed one.
        $this->assertNotSame($this->resolve('Fresh thyme')->id, $this->resolve('dried thyme')->id);
    }

    /**
     * "Freshly grated" describes the grinding, not the ingredient — the cook
     * grates the jar. The parser drops the word, and what is left must still
     * find the jar rather than being read as a request for fresh.
     */
    public function test_freshly_grated_is_not_a_request_for_fresh(): void
    {
        $parsed = IngredientLine::parse('1 tsp freshly grated nutmeg');

        $this->assertTrue($this->resolve($parsed->name)->isStaple(), $parsed->name);
    }

    /**
     * Whole-name matching, not substring. Getting this wrong would suppress
     * the single most-used ingredient in the archive.
     */
    public function test_garlic_and_onion_and_peppers_are_not_swallowed(): void
    {
        foreach ([
            'Garlic', 'Garlic cloves', 'Onion', 'Red onion', 'Red bell pepper',
            'Red pepper', 'Yellow mustard', 'Smoked paprika', 'Bell pepper',
        ] as $name) {
            $this->assertNull(PantryStaples::match($name), "{$name} must not match a staple");
        }

        // While the jars themselves still do.
        $this->assertNotNull(PantryStaples::match('Garlic powder'));
        $this->assertNotNull(PantryStaples::match('Onion powder'));
        $this->assertNotNull(PantryStaples::match('Red pepper flakes'));
    }

    // -------------------------------------------------------- grocery list

    /** The complaint, in one test: half a teaspoon of paprika is not shopping. */
    public function test_a_staple_never_reaches_the_grocery_list(): void
    {
        $recipe = $this->recipeWith('Weeknight Chicken', [
            'Chicken breast' => 1.0,
            'Paprika' => 0.5,
            'Italian seasoning' => 1.0,
            'Dried oregano' => 1.0,
        ]);

        // Planning is what builds the list (spec 4.6), so no separate call.
        $this->plan($recipe);

        $listed = GroceryListItem::pluck('item_name')->all();

        $this->assertSame(['Chicken breast'], $listed);
    }

    /** And the counterpart: fresh still gets bought. */
    public function test_fresh_herbs_still_reach_the_grocery_list(): void
    {
        $recipe = $this->recipeWith('Caprese', [
            'Fresh basil' => 1.0,
            'Basil leaves' => 1.0,
        ]);

        $this->plan($recipe);

        $this->assertSame(['Fresh basil'], GroceryListItem::pluck('item_name')->all());
    }

    // ------------------------------------------------------- better fresh

    /**
     * A recipe saying only "basil" has not said which it wants. The jar is
     * assumed so the line stays off the list, and the recipe page says fresh
     * would be better rather than deciding for the cook.
     */
    public function test_an_ambiguous_herb_assumes_the_jar_and_flags_it(): void
    {
        $basil = $this->resolve('basil');

        $this->assertTrue($basil->isStaple(), 'bare basil should assume the jar');
        $this->assertTrue($basil->betterFresh());

        // Spices are not better fresh in any sense worth a hint.
        $this->assertFalse($this->resolve('Paprika')->betterFresh());
        $this->assertFalse($this->resolve('Taco seasoning')->betterFresh());
    }

    public function test_the_recipe_page_says_when_fresh_would_be_better(): void
    {
        $recipe = $this->recipeWith('Tomato Pasta', ['Oregano' => 1.0]);

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('better fresh')
            ->assertSee('spice rack');
    }

    // ------------------------------------------------------------- pantry

    /** They do not expire, so they must never join the use-up list. */
    public function test_a_staple_never_expires_or_gets_used_up(): void
    {
        $recipe = $this->recipeWith('Chili', ['Chili powder' => 2.0]);
        $this->artisan('pantry:staples')->assertSuccessful();

        $chilli = $this->resolve('Chili powder');
        $flag = InventoryFlag::where('ingredient_id', $chilli->id)->firstOrFail();

        $this->assertTrue($flag->has_stock);
        $this->assertNull($flag->expires_on);
        $this->assertFalse($chilli->isPerishable());

        // Cooking with it three times over must not drain the jar.
        for ($i = 0; $i < 3; $i++) {
            (new InventoryService)->consumeForRecipe($recipe->fresh(), 4);
        }

        $this->assertTrue($flag->fresh()->has_stock);
        $this->assertNotContains(
            $chilli->id,
            (new InventoryService)->atRiskIngredientIds(Carbon::today())->all(),
        );
    }

    public function test_the_pantry_page_lists_the_rack_separately(): void
    {
        $this->artisan('pantry:staples')->assertSuccessful();

        $this->actingAs($this->user)
            ->get(route('pantry'))
            ->assertOk()
            ->assertSee('Spice rack')
            ->assertSee('Italian seasoning');
    }

    // ------------------------------------------------------------ command

    public function test_the_command_stocks_the_rack(): void
    {
        $this->artisan('pantry:staples')->assertSuccessful();

        $this->assertSame(
            count(PantryStaples::all()),
            Ingredient::where('is_staple', true)->count(),
        );
        $this->assertSame(
            count(PantryStaples::all()),
            InventoryFlag::where('has_stock', true)->count(),
        );
    }

    /**
     * The archive predates the rack, so rows like "Ground cumin" already exist
     * with recipes attached. They have to be marked too, or they go on raising
     * grocery lines for a jar already in the house.
     */
    public function test_existing_rows_under_other_names_are_adopted(): void
    {
        $existing = Ingredient::create([
            'name' => 'Ground cumin',
            'category' => IngredientCategory::PantryDry,
            'shelf_life_days' => 400,
        ]);
        $fresh = Ingredient::create([
            'name' => 'Fresh thyme',
            'category' => IngredientCategory::Produce,
            'shelf_life_days' => 6,
        ]);

        $this->artisan('pantry:staples')->assertSuccessful();

        $this->assertTrue($existing->fresh()->isStaple());
        // Untouched: it keeps its own row and its recipes, it is only marked.
        $this->assertSame('Ground cumin', $existing->fresh()->name);
        $this->assertFalse($fresh->fresh()->isStaple());
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $this->artisan('pantry:staples --dry-run')->assertSuccessful();

        $this->assertSame(0, Ingredient::where('is_staple', true)->count());
        $this->assertSame(0, InventoryFlag::count());
    }

    // ------------------------------------------------------------ helpers

    /** @param  array<string, float>  $ingredients */
    private function recipeWith(string $name, array $ingredients): Recipe
    {
        $recipe = Recipe::create([
            'name' => $name,
            'base_servings' => 4,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        foreach ($ingredients as $ingredientName => $perServing) {
            $recipe->ingredients()->attach($this->resolve($ingredientName)->id, [
                'id' => (string) Str::uuid(),
                'quantity_per_serving' => $perServing,
                'unit' => null,
            ]);
        }

        return $recipe->fresh();
    }

    private function plan(Recipe $recipe): MealComponent
    {
        return (new MealPlanner)->setPrimaryRecipe(
            Carbon::parse('2026-09-09'),
            MealSlot::Dinner,
            $recipe,
        );
    }
}
