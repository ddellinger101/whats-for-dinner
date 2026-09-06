<?php

namespace Tests\Feature;

use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\IngredientUseByWindow;
use App\Models\InventoryFlag;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Folding duplicate ingredient rows into one.
 *
 * Years of scraping left the archive calling one thing several names, so each
 * spelling kept its own pantry stock, its own use-by windows and its own
 * grocery lines, and none of them added up.
 */
class MergeIngredientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        User::factory()->create();
    }

    private function ingredient(string $name, IngredientCategory $category = IngredientCategory::Dairy): Ingredient
    {
        return Ingredient::create([
            'name' => $name,
            'category' => $category,
            'shelf_life_days' => $category->defaultShelfLifeDays(),
        ]);
    }

    private function attach(Recipe $recipe, Ingredient $ingredient, ?float $perServing, ?string $unit = null): void
    {
        $recipe->ingredients()->attach($ingredient->id, [
            'id' => (string) Str::uuid(),
            'quantity_per_serving' => $perServing,
            'unit' => $unit,
        ]);
    }

    private function recipe(string $name = 'Alfredo'): Recipe
    {
        return Recipe::create([
            'name' => $name,
            'base_servings' => 4,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);
    }

    // --------------------------------------------------------------- basics

    public function test_the_dry_run_writes_nothing(): void
    {
        $keep = $this->ingredient('Heavy cream');
        $drop = $this->ingredient('Heavy whipping cream');
        $this->attach($this->recipe(), $drop, 0.25, 'cup');

        $this->artisan('ingredients:merge "Heavy cream" "Heavy whipping cream"')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNotNull($drop->fresh());
        $this->assertSame(1, DB::table('recipe_ingredient')->where('ingredient_id', $drop->id)->count());
        $this->assertSame(0, DB::table('recipe_ingredient')->where('ingredient_id', $keep->id)->count());
    }

    public function test_recipes_move_to_the_kept_row_and_the_loser_goes(): void
    {
        $keep = $this->ingredient('Heavy cream');
        $drop = $this->ingredient('Heavy whipping cream');
        $recipe = $this->recipe();
        $this->attach($recipe, $drop, 0.25, 'cup');

        $this->artisan('ingredients:merge "Heavy cream" "Heavy whipping cream" --apply')->assertSuccessful();

        $this->assertNull($drop->fresh());
        $this->assertSame(['Heavy cream'], $recipe->fresh()->ingredients->pluck('name')->all());
        $this->assertEqualsWithDelta(
            0.25,
            (float) $recipe->fresh()->ingredients->first()->pivot->quantity_per_serving,
            0.0001,
        );
    }

    public function test_several_names_can_be_folded_in_at_once(): void
    {
        $this->ingredient('Chicken breasts', IngredientCategory::Protein);
        $this->ingredient('Boneless skinless chicken breasts', IngredientCategory::Protein);
        $this->ingredient('Boneless chicken breasts', IngredientCategory::Protein);

        $this->artisan('ingredients:merge "Chicken breasts" "Boneless skinless chicken breasts" "Boneless chicken breasts" --apply')
            ->assertSuccessful();

        $this->assertSame(1, Ingredient::whereRaw('LOWER(name) LIKE ?', ['%chicken breast%'])->count());
    }

    // ------------------------------------------------------ the constraints

    /**
     * A recipe can list both rows — cream in the sauce and whipping cream in
     * the topping — and the pivot is unique per pair, so one has to go.
     */
    public function test_a_recipe_holding_both_rows_keeps_one_line(): void
    {
        $keep = $this->ingredient('Heavy cream');
        $drop = $this->ingredient('Heavy whipping cream');
        $recipe = $this->recipe();
        $this->attach($recipe, $keep, 0.25, 'cup');
        $this->attach($recipe, $drop, 0.5, 'cup');

        $this->artisan('ingredients:merge "Heavy cream" "Heavy whipping cream" --apply')->assertSuccessful();

        $ingredients = $recipe->fresh()->ingredients;
        $this->assertCount(1, $ingredients);
        // The kept row's own amount stands.
        $this->assertEqualsWithDelta(0.25, (float) $ingredients->first()->pivot->quantity_per_serving, 0.0001);
    }

    /** Except where the kept line has no amount and the loser does. */
    public function test_an_amount_beats_no_amount(): void
    {
        $keep = $this->ingredient('Heavy cream');
        $drop = $this->ingredient('Heavy whipping cream');
        $recipe = $this->recipe();
        $this->attach($recipe, $keep, null);
        $this->attach($recipe, $drop, 0.5, 'cup');

        $this->artisan('ingredients:merge "Heavy cream" "Heavy whipping cream" --apply')->assertSuccessful();

        $pivot = $recipe->fresh()->ingredients->first()->pivot;
        $this->assertEqualsWithDelta(0.5, (float) $pivot->quantity_per_serving, 0.0001);
        $this->assertSame('cup', $pivot->unit);
    }

    /** One pantry entry per ingredient, so two have to become one. */
    public function test_two_pantry_entries_combine(): void
    {
        $keep = $this->ingredient('Heavy cream');
        $drop = $this->ingredient('Heavy whipping cream');

        InventoryFlag::create([
            'ingredient_id' => $keep->id,
            'has_stock' => true,
            'quantity' => 1,
            'unit' => 'cup',
            'acquired_on' => Carbon::today(),
            'expires_on' => Carbon::today()->addDays(10),
        ]);
        InventoryFlag::create([
            'ingredient_id' => $drop->id,
            'has_stock' => true,
            'quantity' => 2,
            'unit' => 'cup',
            'acquired_on' => Carbon::today(),
            'expires_on' => Carbon::today()->addDays(3),
        ]);

        $this->artisan('ingredients:merge "Heavy cream" "Heavy whipping cream" --apply')->assertSuccessful();

        $flags = InventoryFlag::all();
        $this->assertCount(1, $flags);
        $this->assertEqualsWithDelta(3.0, (float) $flags->first()->quantity, 0.0001);
        // The sooner date is the one that matters.
        $this->assertTrue($flags->first()->expires_on->isSameDay(Carbon::today()->addDays(3)));
    }

    /** Unique per ingredient and week. */
    public function test_a_clashing_use_by_window_is_dropped_not_duplicated(): void
    {
        $keep = $this->ingredient('Heavy cream');
        $drop = $this->ingredient('Heavy whipping cream');
        $week = Carbon::parse('2026-09-06');

        foreach ([$keep, $drop] as $ingredient) {
            IngredientUseByWindow::create([
                'ingredient_id' => $ingredient->id,
                'week_start_date' => $week,
                'purchase_date' => $week,
                'expires_on' => $week->copy()->addDays(6),
            ]);
        }

        $this->artisan('ingredients:merge "Heavy cream" "Heavy whipping cream" --apply')->assertSuccessful();

        $this->assertSame(1, IngredientUseByWindow::count());
        $this->assertSame($keep->id, IngredientUseByWindow::first()->ingredient_id);
    }

    public function test_grocery_lines_are_repointed_including_purchased_history(): void
    {
        $keep = $this->ingredient('Heavy cream');
        $drop = $this->ingredient('Heavy whipping cream');

        $bought = GroceryListItem::create([
            'item_name' => 'Heavy whipping cream',
            'ingredient_id' => $drop->id,
            'source' => GroceryItemSource::Manual,
            'status' => GroceryItemStatus::Purchased,
            'added_date' => Carbon::today(),
        ]);
        $bought->delete();

        $this->artisan('ingredients:merge "Heavy cream" "Heavy whipping cream" --apply')->assertSuccessful();

        // Soft-deleted history is repointed too, or it would be orphaned by the
        // foreign key and lose its link to the ingredient.
        $this->assertSame($keep->id, GroceryListItem::withTrashed()->find($bought->id)->ingredient_id);
    }

    // ------------------------------------------------------------ the guard

    /**
     * The distinction the household asked for is worth protecting from a
     * careless merge: a jar of dried thyme does not satisfy a recipe wanting
     * fresh.
     */
    public function test_merging_across_the_fresh_line_is_refused(): void
    {
        $this->ingredient('Parsley flakes', IngredientCategory::PantryDry);
        $this->ingredient('Fresh parsley', IngredientCategory::Produce);

        $this->artisan('ingredients:merge "Parsley flakes" "Fresh parsley" --apply')
            ->expectsOutputToContain('disagree about fresh')
            ->assertFailed();

        $this->assertNotNull(Ingredient::whereRaw('LOWER(name) = ?', ['fresh parsley'])->first());
    }

    public function test_force_overrides_the_fresh_guard(): void
    {
        $this->ingredient('Fresh parsley', IngredientCategory::Produce);
        $this->ingredient('Fresh parsley leaves', IngredientCategory::Produce);
        $this->ingredient('Parsley flakes', IngredientCategory::PantryDry);

        $this->artisan('ingredients:merge "Parsley flakes" "Fresh parsley" --apply --force')
            ->assertSuccessful();

        $this->assertNull(Ingredient::whereRaw('LOWER(name) = ?', ['fresh parsley'])->first());
    }

    // ------------------------------------------------------------- misuse

    public function test_an_unknown_name_fails_and_suggests_near_misses(): void
    {
        $this->ingredient('Heavy cream');

        $this->artisan('ingredients:merge "Heavy cream" "Heavy creem"')
            ->expectsOutputToContain('No ingredient named')
            ->expectsOutputToContain('Heavy cream')
            ->assertFailed();
    }

    public function test_merging_a_row_into_itself_is_refused(): void
    {
        $this->ingredient('Heavy cream');

        $this->artisan('ingredients:merge "Heavy cream" "heavy cream"')->assertFailed();
    }
}
