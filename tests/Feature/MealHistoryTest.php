<?php

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\Recipe;
use App\Models\SimpleItem;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\MealPlanner;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Marking a meal made after the fact.
 *
 * Forgetting is something you notice the next morning, and until this there
 * was no way back to it: the screen only ever showed today's dinner, so the
 * ingredients had to be taken out of the pantry by hand.
 */
class MealHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const TODAY = '2026-09-09';

    private const YESTERDAY = '2026-09-08';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
        Carbon::setTestNow(Carbon::parse(self::TODAY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function recipe(string $name = 'Chicken Caesar Salad'): Recipe
    {
        $recipe = Recipe::create([
            'name' => $name,
            'base_servings' => 4,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        $lettuce = Ingredient::create([
            'name' => 'Romaine lettuce',
            'category' => IngredientCategory::Produce,
            'shelf_life_days' => 6,
        ]);

        $recipe->ingredients()->attach($lettuce->id, [
            'id' => (string) Str::uuid(),
            'quantity_per_serving' => 0.5,
            'unit' => 'cup',
        ]);

        return $recipe->fresh();
    }

    // ------------------------------------------------------------- history

    /** The complaint, exactly: last night's dinner, marked this morning. */
    public function test_yesterdays_dinner_can_be_marked_made_today(): void
    {
        $recipe = $this->recipe();
        (new MealPlanner)->setPrimaryRecipe(Carbon::parse(self::YESTERDAY), MealSlot::Dinner, $recipe);

        $lettuce = $recipe->ingredients->first();
        (new InventoryService)->add($lettuce, 10, 'cup');

        $this->actingAs($this->user)
            ->get(route('tonight', ['date' => self::YESTERDAY]))
            ->assertOk()
            ->assertSee('Chicken Caesar Salad')
            ->assertSee('Mark as made on 8 Sep');

        $this->actingAs($this->user)
            ->post(route('recipes.cooked', $recipe), ['cooked_on' => self::YESTERDAY])
            ->assertRedirect();

        // Recorded on the night it was eaten, not on the morning it was
        // remembered — the rotation's recency penalty would be a day out.
        $this->assertSame(self::YESTERDAY, $recipe->fresh()->last_cooked_on->toDateString());

        // And the pantry was drawn down, which is the whole point.
        $flag = InventoryFlag::where('ingredient_id', $lettuce->id)->firstOrFail();
        $this->assertEqualsWithDelta(8.0, (float) $flag->quantity, 0.001);
    }

    /** A meal not yet cooked cannot have been. */
    public function test_a_future_date_is_refused(): void
    {
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.cooked', $recipe), ['cooked_on' => '2026-09-20'])
            ->assertSessionHasErrors('cooked_on');

        $this->assertNull($recipe->fresh()->last_cooked_on);
    }

    /** Marking an older meal must not undo a more recent cook. */
    public function test_the_last_cooked_date_only_moves_forward(): void
    {
        $recipe = $this->recipe();
        $recipe->update(['last_cooked_on' => self::TODAY]);

        $this->actingAs($this->user)
            ->post(route('recipes.cooked', $recipe), ['cooked_on' => '2026-09-01']);

        $this->assertSame(self::TODAY, $recipe->fresh()->last_cooked_on->toDateString());
        // It still counts as having been made.
        $this->assertSame(1, $recipe->fresh()->times_made);
    }

    public function test_the_day_can_be_stepped_through(): void
    {
        // Escaped, since the ampersand between the query parameters is written
        // as an entity in the markup.
        $this->actingAs($this->user)->get(route('tonight'))
            ->assertOk()
            ->assertSee(e(route('tonight', ['date' => self::YESTERDAY, 'slot' => 'dinner'])), false)
            ->assertSee(e(route('tonight', ['date' => '2026-09-10', 'slot' => 'dinner'])), false);
    }

    // ------------------------------------------------- every component

    /**
     * The complaint: a lunch is three sandwiches, each its own component, and
     * only the main dish had a way to say it was eaten. Everything else on the
     * table was a label you could not act on, so breakfast and lunch could
     * never draw the pantry down.
     */
    public function test_a_side_can_be_marked_made_and_empties_the_pantry(): void
    {
        $sandwich = $this->recipe('Turkey Sandwich');
        $component = (new MealPlanner)->addSide(
            Carbon::parse(self::TODAY),
            MealSlot::Lunch,
            $sandwich,
        );

        $this->assertFalse($component->is_primary, 'a side, which is the case that was broken');

        $lettuce = $sandwich->ingredients->first();
        (new InventoryService)->add($lettuce, 10, 'cup');

        $this->actingAs($this->user)
            ->get(route('tonight', ['slot' => 'lunch']))
            ->assertOk()
            ->assertSee('Made it');

        $this->actingAs($this->user)
            ->post(route('plan.component.made', $component), ['cooked_on' => self::TODAY])
            ->assertRedirect();

        $this->assertNotNull($component->fresh()->made_at);
        $this->assertSame(1, $sandwich->fresh()->times_made);
        $this->assertEqualsWithDelta(
            6.0,
            (float) InventoryFlag::where('ingredient_id', $lettuce->id)->value('quantity'),
            0.001,
        );
    }

    /** Ticking twice must not take the ingredients out twice. */
    public function test_marking_made_twice_does_not_empty_the_pantry_twice(): void
    {
        $sandwich = $this->recipe('Turkey Sandwich');
        $component = (new MealPlanner)->addSide(Carbon::parse(self::TODAY), MealSlot::Lunch, $sandwich);

        $lettuce = $sandwich->ingredients->first();
        (new InventoryService)->add($lettuce, 10, 'cup');

        $this->actingAs($this->user)->post(route('plan.component.made', $component));
        $this->actingAs($this->user)->post(route('plan.component.made', $component))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'already marked made'));

        $this->assertSame(1, $sandwich->fresh()->times_made);
        $this->assertEqualsWithDelta(
            6.0,
            (float) InventoryFlag::where('ingredient_id', $lettuce->id)->value('quantity'),
            0.001,
        );
    }

    /**
     * A simple item never carried an amount, so use is recorded without one.
     * Two omelettes are not two eggs, and guessing would be worse than saying
     * nothing.
     */
    public function test_a_simple_item_records_use_without_inventing_an_amount(): void
    {
        $eggs = Ingredient::create([
            'name' => 'Eggs',
            'category' => IngredientCategory::Dairy,
            'shelf_life_days' => 21,
        ]);
        (new InventoryService)->add($eggs, 12, null);

        $omelette = SimpleItem::create(['name' => 'Omelette', 'grocery_breakdown' => ['Eggs']]);
        $component = (new MealPlanner)->addSide(Carbon::parse(self::TODAY), MealSlot::Breakfast, $omelette);

        $this->actingAs($this->user)
            ->post(route('plan.component.made', $component))
            ->assertRedirect();

        $this->assertNotNull($component->fresh()->made_at);

        // Still there, still twelve: the app does not know how many an omelette
        // takes, and it does not pretend to.
        $flag = InventoryFlag::where('ingredient_id', $eggs->id)->firstOrFail();
        $this->assertTrue($flag->has_stock);
        $this->assertEqualsWithDelta(12.0, (float) $flag->quantity, 0.001);
        $this->assertNotNull($flag->last_updated);
    }

    /** Nothing is invented for a breakdown line the pantry has never seen. */
    public function test_a_simple_item_does_not_create_ingredients_to_consume(): void
    {
        $item = SimpleItem::create(['name' => 'Snack', 'grocery_breakdown' => ['1 banana']]);
        $component = (new MealPlanner)->addSide(Carbon::parse(self::TODAY), MealSlot::Breakfast, $item);

        $before = Ingredient::count();

        $this->actingAs($this->user)->post(route('plan.component.made', $component))->assertRedirect();

        $this->assertSame($before, Ingredient::count());
        $this->assertNotNull($component->fresh()->made_at);
    }

    // --------------------------------------------------------- the slots

    public function test_breakfast_and_lunch_have_the_same_screen(): void
    {
        $item = SimpleItem::create(['name' => 'Porridge']);
        (new MealPlanner)->addSide(Carbon::parse(self::TODAY), MealSlot::Breakfast, $item);

        $this->actingAs($this->user)
            ->get(route('tonight', ['slot' => 'breakfast']))
            ->assertOk()
            ->assertSee('For Breakfast')
            ->assertSee('Porridge');

        $this->actingAs($this->user)
            ->get(route('tonight', ['slot' => 'lunch']))
            ->assertOk()
            ->assertSee('For Lunch');
    }

    /** An unknown slot falls back to dinner rather than erroring. */
    public function test_an_unknown_slot_shows_dinner(): void
    {
        $this->actingAs($this->user)
            ->get(route('tonight', ['slot' => 'brunch']))
            ->assertOk()
            ->assertSee('For Dinner');
    }

    public function test_the_home_screen_offers_all_three(): void
    {
        $item = SimpleItem::create(['name' => 'Porridge']);
        (new MealPlanner)->addSide(Carbon::parse(self::TODAY), MealSlot::Breakfast, $item);

        $this->actingAs($this->user)->get(route('home'))
            ->assertOk()
            ->assertSee('For Breakfast')
            ->assertSee('For Lunch')
            ->assertSee('For Dinner')
            // The smaller buttons still say what is on, without a tap.
            ->assertSee('Porridge');
    }

    // ------------------------------------------------------- the plan grid

    /** Six days of scrolling to reach tonight is the wrong first move. */
    public function test_the_plan_marks_today_for_the_scroll(): void
    {
        $this->actingAs($this->user)
            ->get(route('plan', ['start' => '2026-09-06']))
            ->assertOk()
            ->assertSee('id="today"', false);
    }

    /** A week that does not contain today has nothing to scroll to. */
    public function test_another_week_is_not_scrolled(): void
    {
        $this->actingAs($this->user)
            ->get(route('plan', ['start' => '2026-10-04']))
            ->assertOk()
            ->assertDontSee('id="today"', false);
    }
}
