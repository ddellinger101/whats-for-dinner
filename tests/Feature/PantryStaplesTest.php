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
use App\Support\IngredientCategoryGuesser;
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

    /**
     * The archive writes these sixteen ways. Enumerating them would still miss
     * the seventeenth, so the qualifiers are stripped and whatever remains has
     * to be nothing but salt and pepper.
     */
    public function test_salt_and_pepper_are_caught_however_they_are_written(): void
    {
        foreach ([
            'Salt' => 'Salt',
            // Kosher salt has a jar of its own — see the test below.
            'Kosher salt' => 'Kosher salt',
            'Sea salt' => 'Salt',
            'Pepper' => 'Black pepper',
            'Black pepper' => 'Black pepper',
            'Ground black pepper' => 'Black pepper',
            // No fresh form of a peppercorn: this is about the grinding.
            'Fresh ground black pepper' => 'Black pepper',
            'Salt and pepper' => 'Salt and pepper',
            'Salt & pepper' => 'Salt and pepper',
            'Salt/pepper' => 'Salt and pepper',
            'Salt and plenty of black pepper' => 'Salt and pepper',
            'Kosher salt and fresh ground black pepper' => 'Salt and pepper',
        ] as $written => $expected) {
            $this->assertSame($expected, PantryStaples::match($written)?->name, $written);
        }
    }

    /**
     * Kosher salt is its own jar rather than an alias of Salt: it is what the
     * household reaches for, and the grains are a different size, so a recipe
     * asking for one does not mean the other. It has to be matched before the
     * qualifier-stripping rule reduces it to "salt".
     */
    public function test_kosher_salt_is_its_own_jar(): void
    {
        $this->assertSame('Kosher salt', PantryStaples::match('Kosher salt')?->name);
        $this->assertSame('Kosher salt', PantryStaples::match('coarse kosher salt')?->name);

        // The other salts still fold into the plain one.
        $this->assertSame('Salt', PantryStaples::match('Sea salt')?->name);
        $this->assertSame('Salt', PantryStaples::match('Table salt')?->name);

        // And a phrase naming both still means both.
        $this->assertSame(
            'Salt and pepper',
            PantryStaples::match('Kosher salt and fresh ground black pepper')?->name,
        );
    }

    /**
     * The qualifier list is short on purpose. These share a word with salt or
     * pepper and are entirely different things.
     */
    public function test_the_salt_and_pepper_rule_does_not_overreach(): void
    {
        foreach (['Red pepper', 'Red bell pepper', 'Salted butter', 'Pepper jack velveeta', 'Seasoned salt'] as $name) {
            $this->assertNull(PantryStaples::match($name), $name);
        }

        // These are their own jars and keep their own identity.
        $this->assertSame('White pepper', PantryStaples::match('White pepper')?->name);
        $this->assertSame('Garlic salt', PantryStaples::match('Garlic salt')?->name);
        $this->assertSame('Lemon pepper seasoning', PantryStaples::match('Lemon pepper')?->name);
    }

    /** "Salt and pepper" is two jars, so there is no such jar to stock. */
    public function test_a_phrase_that_is_not_a_jar_is_never_stocked_on_the_rack(): void
    {
        $this->artisan('pantry:staples')->assertSuccessful();

        $combined = Ingredient::whereRaw('LOWER(name) = ?', ['salt and pepper'])->first();

        if ($combined) {
            $this->assertNull(InventoryFlag::where('ingredient_id', $combined->id)->first());
        }

        $this->assertNotNull(
            InventoryFlag::whereIn(
                'ingredient_id',
                Ingredient::whereRaw('LOWER(name) = ?', ['salt'])->pluck('id'),
            )->first(),
        );
    }

    /**
     * The distinction the household drew: red pepper is produce, crushed red
     * pepper is the jar. The parser was stripping "crushed" as a preparation
     * note, so half a teaspoon of chilli flakes arrived as a bell pepper.
     */
    public function test_crushed_red_pepper_is_the_jar_and_red_pepper_is_produce(): void
    {
        $this->assertSame('Crushed red pepper', IngredientLine::parse('1/2 tsp crushed red pepper')->name);
        $this->assertSame('Red pepper', IngredientLine::parse('1 red pepper, diced')->name);

        $this->assertTrue($this->resolve('Crushed red pepper')->isStaple());
        $this->assertFalse($this->resolve('Red pepper')->isStaple());

        // The same trap, same fix: a can of crushed tomatoes is not a tomato.
        $this->assertSame('Crushed tomatoes', IngredientLine::parse('1 cup crushed tomatoes')->name);
        // While a genuine preparation note is still dropped.
        $this->assertSame('Garlic', IngredientLine::parse('2 cloves garlic, crushed')->name);
    }

    /**
     * The rack is dry goods by definition, settled before any keyword gets a
     * look. Left to the rules, "Cumin ground" was Protein on the word "ground"
     * — three days' shelf life — and "Thyme leaves" was Produce on six, which
     * would have put the whole rack in the use-these-up list within a week.
     */
    public function test_the_rack_is_always_dry_goods_whatever_the_keywords_say(): void
    {
        $guesser = new IngredientCategoryGuesser;

        foreach ([
            'Cumin ground', 'Nutmeg ground', 'Allspice ground', 'Cloves ground',
            'Thyme leaves', 'Rosemary leaves', 'Basil leaves', 'Crushed red pepper',
            'Minced onion', 'Garlic salt', 'Pork rub', 'Yellow mustard seed',
        ] as $jar) {
            $this->assertSame(IngredientCategory::PantryDry, $guesser->guess($jar), $jar);
        }

        // And fresh is produce, as it should be.
        $this->assertSame(IngredientCategory::Produce, $guesser->guess('Fresh thyme'));
        $this->assertSame(IngredientCategory::Produce, $guesser->guess('Red pepper'));
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

    /**
     * Recomputing every use-by date from shelf life gave all forty-two jars an
     * expiry a year out. The right date for something that does not expire is
     * no date.
     */
    public function test_refreshing_dates_leaves_the_rack_without_one(): void
    {
        $this->artisan('pantry:staples')->assertSuccessful();
        // Both flags together must do both jobs: --refresh-dates used to
        // return before the category pass, so this reported only the dates
        // while looking like it had done the lot.
        $this->artisan('ingredients:recategorise --apply --refresh-dates')->assertSuccessful();

        $stapleIds = Ingredient::where('is_staple', true)->pluck('id');

        $this->assertSame(
            0,
            InventoryFlag::whereIn('ingredient_id', $stapleIds)->whereNotNull('expires_on')->count(),
        );

        // The category pass ran too, rather than being skipped.
        $this->assertSame(
            IngredientCategory::PantryDry,
            $this->resolve('Baking soda')->fresh()->category,
        );
    }

    /** A date printed on the packet beats one worked out from a shelf life. */
    public function test_a_printed_use_by_date_can_be_given(): void
    {
        $this->artisan('pantry:add "White american cheese" --apply --expires=2026-09-01')
            ->assertSuccessful();

        $flag = InventoryFlag::whereHas('ingredient', fn ($q) => $q->where('name', 'White american cheese'))
            ->firstOrFail();

        $this->assertSame('2026-09-01', $flag->expires_on->toDateString());
    }

    /**
     * Half a shelf of vinegar came out dated and half undated, because the
     * bottles already in the pantry were skipped whole rather than having the
     * stated date applied.
     */
    public function test_a_date_given_reaches_rows_already_in_the_pantry(): void
    {
        $this->artisan('pantry:add "Olive oil" --apply')->assertSuccessful();

        $flag = InventoryFlag::whereHas('ingredient', fn ($q) => $q->where('name', 'Olive oil'))
            ->firstOrFail();
        $this->assertNotNull($flag->expires_on, 'the shelf life applies on the way in');

        $this->artisan('pantry:add "Olive oil" --apply --no-expiry')->assertSuccessful();

        $this->assertNull($flag->fresh()->expires_on);
    }

    public function test_an_unreadable_date_is_refused_before_anything_is_written(): void
    {
        $this->artisan('pantry:add "White american cheese" --apply --expires=09.01.26')
            ->assertFailed();

        $this->assertSame(0, InventoryFlag::count());
    }

    /**
     * Not only staples. A shelf of sauces was put in without dates on purpose,
     * and recomputing from shelf life would hand ketchup a use-by window.
     */
    public function test_a_deliberately_undated_pantry_row_is_left_undated(): void
    {
        $this->artisan('pantry:add "Table syrup" --apply --no-expiry')->assertSuccessful();

        $flag = InventoryFlag::whereHas('ingredient', fn ($q) => $q->where('name', 'Table syrup'))
            ->firstOrFail();
        $this->assertNull($flag->expires_on);

        $this->artisan('ingredients:recategorise --apply --refresh-dates')->assertSuccessful();

        $this->assertNull($flag->fresh()->expires_on);
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

        // Every jar is stocked; "salt and pepper" is a phrase, not a jar, so it
        // is not among them.
        $jars = collect(PantryStaples::all())->filter->onRack;

        $this->assertSame($jars->count(), Ingredient::where('is_staple', true)->count());
        $this->assertSame($jars->count(), InventoryFlag::where('has_stock', true)->count());
        $this->assertContains('Salt', Ingredient::where('is_staple', true)->pluck('name')->all());
        $this->assertContains('Black pepper', Ingredient::where('is_staple', true)->pluck('name')->all());
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
