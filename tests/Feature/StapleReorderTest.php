<?php

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\Recipe;
use App\Models\User;
use App\Services\InventoryService;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A staple is something the household always wants in.
 *
 * That has always meant a recipe wanting half a teaspoon of paprika is no
 * reason to buy paprika. It now also means the jar actually being empty is.
 */
class StapleReorderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
    }

    private function stocked(string $name, bool $staple, ?float $quantity = null): InventoryFlag
    {
        $ingredient = Ingredient::create([
            'name' => $name,
            'category' => IngredientCategory::Dairy,
            'shelf_life_days' => 12,
            'is_staple' => $staple,
        ]);

        return (new InventoryService)->add($ingredient, $quantity, 'lb');
    }

    // --------------------------------------------------------- running out

    public function test_a_staple_marked_gone_lands_on_the_grocery_list(): void
    {
        $flag = $this->stocked('Butter', staple: true);

        (new InventoryService)->markGone($flag);

        $this->assertSame(['Butter'], GroceryListItem::needed()->pluck('item_name')->all());
    }

    /** Anything not marked a staple is left alone, as before. */
    public function test_running_out_of_something_ordinary_adds_nothing(): void
    {
        $flag = $this->stocked('Single cream', staple: false);

        (new InventoryService)->markGone($flag);

        $this->assertSame(0, GroceryListItem::needed()->count());
    }

    /**
     * Four ways for something to run out, and hanging this off the one screen
     * that empties things would have caught only the first.
     */
    public function test_it_catches_every_way_of_running_out(): void
    {
        // Set to zero by hand.
        $byHand = $this->stocked('Butter', staple: true, quantity: 2);
        $this->actingAs($this->user)->post(route('pantry.update', $byHand), [
            'quantity' => 0, 'is_staple' => 1,
        ]);

        // Cooked away.
        $cooked = $this->stocked('Milk', staple: true, quantity: 1);
        $recipe = Recipe::create([
            'name' => 'Porridge',
            'base_servings' => 1,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);
        $recipe->ingredients()->attach($cooked->ingredient_id, [
            'id' => (string) Str::uuid(), 'quantity_per_serving' => 1.0, 'unit' => 'lb',
        ]);
        (new InventoryService)->consumeForRecipe($recipe->fresh(), 1);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $cooked->fresh()->quantity,
            0.001,
            'cooking should draw a staple down now, not skip it',
        );
        $this->assertSame(
            ['Butter', 'Milk'],
            GroceryListItem::needed()->orderBy('item_name')->pluck('item_name')->all(),
        );
    }

    /** The spice rack carries no amounts, so cooking cannot empty a jar. */
    public function test_cooking_does_not_empty_an_unmeasured_jar(): void
    {
        $flag = $this->stocked('Paprika', staple: true, quantity: null);
        $recipe = Recipe::create([
            'name' => 'Stew',
            'base_servings' => 4,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);
        $recipe->ingredients()->attach($flag->ingredient_id, [
            'id' => (string) Str::uuid(), 'quantity_per_serving' => 0.25, 'unit' => 'tsp',
        ]);

        (new InventoryService)->consumeForRecipe($recipe->fresh(), 4);

        $this->assertTrue($flag->fresh()->has_stock);
        $this->assertSame(0, GroceryListItem::needed()->count());
    }

    /** Running out twice should not put it on the list twice. */
    public function test_it_is_not_added_twice(): void
    {
        $flag = $this->stocked('Butter', staple: true);
        $inventory = new InventoryService;

        $inventory->markGone($flag);
        $inventory->add($flag->ingredient, null, 'lb');
        $inventory->markGone($flag->fresh());

        $this->assertSame(1, GroceryListItem::needed()->where('item_name', 'Butter')->count());
    }

    // ------------------------------------------------------------ the toggle

    public function test_the_edit_menu_offers_the_toggle_and_saves_it(): void
    {
        $flag = $this->stocked('Butter', staple: false, quantity: 1);

        $this->actingAs($this->user)->get(route('pantry'))
            ->assertOk()
            ->assertSee('Staple');

        $this->actingAs($this->user)->post(route('pantry.update', $flag), [
            'quantity' => 1, 'is_staple' => 1,
        ])->assertRedirect();

        $this->assertTrue($flag->ingredient->fresh()->isStaple());
    }

    /** Marking something a staple must not be a one-way door. */
    public function test_a_staple_can_be_unmarked_from_the_pantry(): void
    {
        $flag = $this->stocked('Butter', staple: true, quantity: 1);

        // It shows in the staples section, with its edit menu intact.
        $this->actingAs($this->user)->get(route('pantry'))
            ->assertOk()
            ->assertSee(route('pantry.update', $flag), false);

        $this->actingAs($this->user)->post(route('pantry.update', $flag), ['quantity' => 1]);

        $this->assertFalse($flag->ingredient->fresh()->isStaple());
    }
}
