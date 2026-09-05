<?php

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ingredient names that arrived as prose are not merely untidy: the ingredient
 * is the key the whole use-up engine matches on, so a sentence is a row nothing
 * will ever match.
 */
class TidyIngredientsTest extends TestCase
{
    use RefreshDatabase;

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::create([
            'name' => $name,
            'category' => IngredientCategory::Produce,
            'shelf_life_days' => 6,
        ]);
    }

    public function test_it_reports_before_writing(): void
    {
        $messy = $this->ingredient('15oz cans great northern beans');

        $this->artisan('ingredients:tidy')->assertSuccessful();
        $this->assertSame('15oz cans great northern beans', $messy->fresh()->name);

        $this->artisan('ingredients:tidy --apply')->assertSuccessful();
        $this->assertSame('Great northern beans', $messy->fresh()->name);
    }

    public function test_it_strips_labels_and_prose(): void
    {
        $garnish = $this->ingredient('Garnish: parsley');
        $prose = $this->ingredient('Lemon juice: a squeeze of fresh lemon juice brightens the whole dish');

        $this->artisan('ingredients:tidy --apply')->assertSuccessful();

        $this->assertSame('Parsley', $garnish->fresh()->name);
        $this->assertSame('Lemon juice', $prose->fresh()->name);
    }

    /** Section headings name part of a recipe, not something to buy. */
    public function test_headings_are_listed_but_kept_unless_asked(): void
    {
        $heading = $this->ingredient('For the caesar salad');

        $this->artisan('ingredients:tidy --apply')->assertSuccessful();
        $this->assertNotNull(Ingredient::find($heading->id));

        $this->artisan('ingredients:tidy --apply --drop-headings')->assertSuccessful();
        $this->assertNull(Ingredient::find($heading->id));
    }

    /**
     * Cleaning a name often reveals it was already recorded properly elsewhere.
     * Merging is the point — two rows for one thing means two use-by windows.
     */
    public function test_a_cleaned_name_merges_into_the_existing_ingredient(): void
    {
        $good = $this->ingredient('Great northern beans');
        $messy = $this->ingredient('15oz cans great northern beans');

        $recipe = Recipe::create(['name' => 'Bean Soup']);
        $recipe->ingredients()->attach($messy->id, [
            'id' => (string) Str::uuid(), 'quantity_per_serving' => 0.5, 'unit' => 'cup',
        ]);

        $this->artisan('ingredients:tidy --apply')->assertSuccessful();

        $this->assertNull(Ingredient::find($messy->id));
        $this->assertNotNull(Ingredient::find($good->id));
        // The recipe now points at the surviving row.
        $this->assertSame([$good->id], $recipe->fresh()->ingredients->pluck('id')->all());
    }

    /** A recipe already using both must not end up with a duplicate pair. */
    public function test_merging_does_not_violate_the_junction_constraint(): void
    {
        $good = $this->ingredient('Great northern beans');
        $messy = $this->ingredient('15oz cans great northern beans');

        $recipe = Recipe::create(['name' => 'Bean Soup']);
        foreach ([$good, $messy] as $ingredient) {
            $recipe->ingredients()->attach($ingredient->id, [
                'id' => (string) Str::uuid(), 'quantity_per_serving' => 0.5, 'unit' => 'cup',
            ]);
        }

        $this->artisan('ingredients:tidy --apply')->assertSuccessful();

        $this->assertSame(1, DB::table('recipe_ingredient')->where('recipe_id', $recipe->id)->count());
        $this->assertSame([$good->id], $recipe->fresh()->ingredients->pluck('id')->all());
    }

    /** Pantry and grocery references have to follow the merge. */
    public function test_a_merge_carries_pantry_and_grocery_references_over(): void
    {
        $good = $this->ingredient('Great northern beans');
        $messy = $this->ingredient('15oz cans great northern beans');

        InventoryFlag::create(['ingredient_id' => $messy->id, 'has_stock' => true]);
        $line = GroceryListItem::create([
            'item_name' => 'Beans',
            'source' => 'auto_recipe',
            'status' => 'needed',
            'added_date' => '2026-09-09',
            'ingredient_id' => $messy->id,
        ]);

        $this->artisan('ingredients:tidy --apply')->assertSuccessful();

        $this->assertSame($good->id, $line->fresh()->ingredient_id);
        $this->assertSame($good->id, InventoryFlag::firstOrFail()->ingredient_id);
        $this->assertSame(1, InventoryFlag::count());
    }

    public function test_a_clean_list_is_left_alone(): void
    {
        $this->ingredient('Sour cream');
        $this->ingredient('Ground beef');

        $this->artisan('ingredients:tidy --apply')->assertSuccessful();

        $this->assertSame(
            ['Ground beef', 'Sour cream'],
            Ingredient::orderBy('name')->pluck('name')->all(),
        );
    }
}
