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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buying more than the week needs.
 *
 * The plan calls for two chicken breasts; the shop sells eight. The extra is
 * not a mistake — it goes into the pantry, the meals still consume only what
 * they were scaled for, and the remainder ages into "use these up".
 */
class GroceryQuantityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const WEDNESDAY = '2026-09-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->user = User::factory()->create();
        Carbon::setTestNow(Carbon::parse(self::WEDNESDAY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A recipe for four using half a breast per serving, planned for a
     * five-person Wednesday: multiplier 2, so the week needs 4.
     */
    private function plannedChicken(float $perServing = 0.5): GroceryListItem
    {
        $recipe = Recipe::create([
            'name' => 'Chicken Bake',
            'protein_type' => ProteinType::Chicken,
            'base_servings' => 4,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        $chicken = Ingredient::create([
            'name' => 'Chicken breasts',
            'category' => IngredientCategory::Protein,
            'shelf_life_days' => 3,
        ]);

        $recipe->ingredients()->attach($chicken->id, [
            'id' => (string) Str::uuid(),
            'quantity_per_serving' => $perServing,
            'unit' => null,
        ]);

        (new MealPlanner)->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe->fresh());

        return GroceryListItem::where('item_name', 'Chicken breasts')->firstOrFail();
    }

    public function test_the_list_records_what_the_week_asked_for(): void
    {
        $item = $this->plannedChicken();

        $this->assertEqualsWithDelta(4.0, (float) $item->quantity, 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $item->planned_quantity, 0.001);
        $this->assertFalse($item->isOverBought());
    }

    public function test_the_amount_being_bought_can_be_changed(): void
    {
        $item = $this->plannedChicken();

        $this->actingAs($this->user)
            ->post(route('grocery.quantity', $item), ['quantity' => 8])
            ->assertRedirect();

        $item->refresh();
        $this->assertEqualsWithDelta(8.0, (float) $item->quantity, 0.001);
        // What the week needs is untouched, so the surplus stays explicable.
        $this->assertEqualsWithDelta(4.0, (float) $item->planned_quantity, 0.001);
        $this->assertTrue($item->isOverBought());
        $this->assertEqualsWithDelta(4.0, $item->surplus(), 0.001);
    }

    /** The whole eight enters the house, not the four the plan wanted. */
    public function test_buying_the_larger_pack_stocks_all_of_it(): void
    {
        $item = $this->plannedChicken();
        $this->actingAs($this->user)->post(route('grocery.quantity', $item), ['quantity' => 8]);

        $this->actingAs($this->user)->post(route('grocery.toggle', $item->fresh()));

        $flag = InventoryFlag::firstOrFail();
        $this->assertSame('Chicken breasts', $flag->ingredient->name);
        $this->assertEqualsWithDelta(8.0, (float) $flag->quantity, 0.001);
    }

    /**
     * The point of the whole thing: meals consume what they were scaled for,
     * and the rest is left over to be used up.
     */
    public function test_cooking_consumes_only_what_the_meal_needed(): void
    {
        $item = $this->plannedChicken();
        $this->actingAs($this->user)->post(route('grocery.quantity', $item), ['quantity' => 8]);
        $this->actingAs($this->user)->post(route('grocery.toggle', $item->fresh()));

        $recipe = Recipe::firstOrFail();
        $this->actingAs($this->user)->post(route('recipes.cooked', $recipe));

        // Bought 8, the meal used 4, so 4 remain.
        $this->assertEqualsWithDelta(4.0, (float) InventoryFlag::firstOrFail()->quantity, 0.001);
        $this->assertTrue(InventoryFlag::firstOrFail()->has_stock);
    }

    /** And the leftovers steer the next suggestion, which is the point. */
    public function test_the_surplus_becomes_something_to_use_up(): void
    {
        $item = $this->plannedChicken();
        $this->actingAs($this->user)->post(route('grocery.quantity', $item), ['quantity' => 8]);
        $this->actingAs($this->user)->post(route('grocery.toggle', $item->fresh()));
        $this->actingAs($this->user)->post(route('recipes.cooked', Recipe::firstOrFail()));

        $chicken = Ingredient::where('name', 'Chicken breasts')->firstOrFail();

        // Chicken keeps three days, so it is at risk immediately.
        $this->assertTrue(
            (new InventoryService)->atRiskIngredientIds(Carbon::parse(self::WEDNESDAY))
                ->contains($chicken->id),
        );
    }

    /**
     * Realising afterwards that the pack was bigger has to reach the pantry
     * too, or what is on hand stays quietly wrong.
     */
    public function test_editing_an_already_bought_line_corrects_the_pantry(): void
    {
        $item = $this->plannedChicken();
        $this->actingAs($this->user)->post(route('grocery.toggle', $item));

        $this->assertEqualsWithDelta(4.0, (float) InventoryFlag::firstOrFail()->quantity, 0.001);

        $this->actingAs($this->user)->post(route('grocery.quantity', $item->fresh()), ['quantity' => 8]);

        // Adjusted by the difference, not overwritten.
        $this->assertEqualsWithDelta(8.0, (float) InventoryFlag::firstOrFail()->quantity, 0.001);
    }

    public function test_reducing_an_already_bought_line_reduces_the_pantry(): void
    {
        $item = $this->plannedChicken();
        $this->actingAs($this->user)->post(route('grocery.toggle', $item));

        $this->actingAs($this->user)->post(route('grocery.quantity', $item->fresh()), ['quantity' => 1]);

        $this->assertEqualsWithDelta(1.0, (float) InventoryFlag::firstOrFail()->quantity, 0.001);
    }

    /**
     * "Some, amount unknown" is a real state. Adding a number to it would
     * invent a precision nobody has.
     */
    public function test_an_unquantified_pantry_entry_is_left_alone(): void
    {
        $onion = Ingredient::create([
            'name' => 'Onion', 'category' => IngredientCategory::Produce, 'shelf_life_days' => 6,
        ]);
        (new InventoryService)->add($onion, null, null);

        (new InventoryService)->adjustBy($onion, 4);

        $this->assertNull(InventoryFlag::firstOrFail()->quantity);
        $this->assertTrue(InventoryFlag::firstOrFail()->has_stock);
    }

    /** A hand-added line has no plan behind it, so nothing to compare against. */
    public function test_a_manual_line_shows_no_planned_amount(): void
    {
        $item = (new GroceryListBuilder)->addManual('Paper towels', 2);

        $this->assertNull($item->planned_quantity);
        $this->assertFalse($item->isOverBought());
    }

    public function test_the_list_shows_what_the_week_needs_when_over_buying(): void
    {
        $item = $this->plannedChicken();
        $this->actingAs($this->user)->post(route('grocery.quantity', $item), ['quantity' => 8]);

        $this->actingAs($this->user)->get(route('grocery'))
            ->assertOk()
            ->assertSee('for this week')
            ->assertSee('rest to the pantry');
    }

    public function test_changing_a_quantity_requires_authentication(): void
    {
        $item = $this->plannedChicken();

        $this->post(route('grocery.quantity', $item), ['quantity' => 8])->assertRedirect('/login');
        $this->assertEqualsWithDelta(4.0, (float) $item->fresh()->quantity, 0.001);
    }
}
