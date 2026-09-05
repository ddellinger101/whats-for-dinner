<?php

namespace Tests\Feature;

use App\Enums\GroceryItemStatus;
use App\Enums\MealSlot;
use App\Enums\MealTypeHint;
use App\Models\GroceryListItem;
use App\Models\SimpleItem;
use App\Models\User;
use App\Services\MealPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Spec 4.5 — the one-time breakdown prompt. "Turkey Sandwich" on a grocery list
 * is useless in a shop; turkey, bread, cheese and mayo is a shop.
 */
class SimpleItemBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const TODAY = '2026-09-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->user = User::factory()->create();
        Carbon::setTestNow(Carbon::parse(self::TODAY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A brand new item is sent straight to the prompt. */
    public function test_creating_an_item_on_the_fly_opens_the_breakdown_prompt(): void
    {
        $this->actingAs($this->user)
            ->post(route('plan.side', ['date' => self::TODAY, 'slot' => 'lunch']), [
                'new_item_name' => 'Turkey Sandwich',
            ])
            ->assertRedirect();

        $item = SimpleItem::where('name', 'Turkey Sandwich')->firstOrFail();

        $this->actingAs($this->user)
            ->get(route('items.edit', ['simpleItem' => $item, 'new' => 1]))
            ->assertOk()
            ->assertSee('What goes in it?')
            ->assertSee('Skip for now');
    }

    /** Picking an already-known item must not re-ask. */
    public function test_an_already_prompted_item_goes_straight_back_to_the_plan(): void
    {
        $item = SimpleItem::create(['name' => 'Grapes', 'breakdown_prompted' => true]);

        $this->actingAs($this->user)
            ->post(route('plan.side', ['date' => self::TODAY, 'slot' => 'lunch']), [
                'simple_item_id' => $item->id,
            ])
            ->assertRedirect(route('plan', ['start' => '2026-09-06']));
    }

    public function test_saving_a_breakdown_stores_the_lines(): void
    {
        $item = SimpleItem::create(['name' => 'Turkey Sandwich']);

        $this->actingAs($this->user)
            ->post(route('items.update', $item), [
                'breakdown' => "turkey\nbread\ncheese\nmayo",
                'meal_type_hint' => MealTypeHint::Lunch->value,
            ])
            ->assertRedirect(route('items'));

        $item->refresh();
        $this->assertSame(['turkey', 'bread', 'cheese', 'mayo'], $item->grocery_breakdown);
        $this->assertTrue($item->breakdown_prompted);
        $this->assertTrue($item->isCompound());
    }

    /** Commas are as natural as newlines when typing a short list. */
    public function test_a_comma_separated_breakdown_works_too(): void
    {
        $item = SimpleItem::create(['name' => 'Charcuterie Plate']);

        $this->actingAs($this->user)
            ->post(route('items.update', $item), ['breakdown' => 'salami, brie, crackers, grapes']);

        $this->assertSame(['salami', 'brie', 'crackers', 'grapes'], $item->fresh()->grocery_breakdown);
    }

    /** Spec 4.5: skipping falls back to the raw name, and is not re-asked. */
    public function test_skipping_records_the_answer_and_keeps_the_raw_name(): void
    {
        $item = SimpleItem::create(['name' => 'Leftovers']);

        $this->actingAs($this->user)
            ->post(route('items.skip', $item))
            ->assertRedirect(route('plan'));

        $item->refresh();
        $this->assertTrue($item->breakdown_prompted);
        $this->assertSame(['Leftovers'], $item->groceryLines());
    }

    /** Saving an empty breakdown is a valid answer, not a failure. */
    public function test_an_empty_breakdown_is_accepted(): void
    {
        $item = SimpleItem::create(['name' => 'Grapes']);

        $this->actingAs($this->user)->post(route('items.update', $item), ['breakdown' => '']);

        $item->refresh();
        $this->assertTrue($item->breakdown_prompted);
        $this->assertFalse($item->isCompound());
        $this->assertSame(['Grapes'], $item->groceryLines());
    }

    /**
     * The prompt arrives after the item is already on the plan, so the grocery
     * line it generated has to be rebuilt — otherwise the very first use of
     * every compound item stays a useless single line.
     */
    public function test_saving_a_breakdown_rebuilds_the_grocery_line_it_already_created(): void
    {
        $item = SimpleItem::create(['name' => 'Turkey Sandwich']);

        (new MealPlanner)->addSide(Carbon::parse(self::TODAY), MealSlot::Lunch, $item);
        $this->assertSame(['Turkey Sandwich'], GroceryListItem::pluck('item_name')->all());

        $this->actingAs($this->user)->post(route('items.update', $item), [
            'breakdown' => "turkey\nbread\ncheese",
        ]);

        $this->assertSame(
            ['bread', 'cheese', 'turkey'],
            GroceryListItem::pluck('item_name')->sort()->values()->all(),
        );
    }

    /** Shopping decisions already made must survive the rebuild. */
    public function test_a_purchased_line_is_left_alone(): void
    {
        $item = SimpleItem::create(['name' => 'Turkey Sandwich']);
        (new MealPlanner)->addSide(Carbon::parse(self::TODAY), MealSlot::Lunch, $item);

        GroceryListItem::firstOrFail()->markPurchased();

        $this->actingAs($this->user)->post(route('items.update', $item), ['breakdown' => "turkey\nbread"]);

        $this->assertSame(['Turkey Sandwich'], GroceryListItem::pluck('item_name')->all());
        $this->assertSame(GroceryItemStatus::Purchased, GroceryListItem::firstOrFail()->status);
    }

    /** A past meal's list is history and should not be rewritten. */
    public function test_past_meals_are_not_rebuilt(): void
    {
        $item = SimpleItem::create(['name' => 'Turkey Sandwich']);
        (new MealPlanner)->addSide(Carbon::parse('2026-09-01'), MealSlot::Lunch, $item);

        $this->actingAs($this->user)->post(route('items.update', $item), ['breakdown' => "turkey\nbread"]);

        $this->assertSame(['Turkey Sandwich'], GroceryListItem::pluck('item_name')->all());
    }

    public function test_the_library_lists_saved_items(): void
    {
        SimpleItem::create(['name' => 'Grapes', 'meal_type_hint' => MealTypeHint::Lunch]);
        SimpleItem::create([
            'name' => 'Side Salad',
            'meal_type_hint' => MealTypeHint::DinnerSide,
            'grocery_breakdown' => ['lettuce', 'tomato'],
        ]);

        $this->actingAs($this->user)->get(route('items'))
            ->assertOk()
            ->assertSee('Grapes')
            ->assertSee('Side Salad')
            ->assertSee('lettuce, tomato');
    }

    public function test_an_item_can_be_deleted_from_the_library(): void
    {
        $item = SimpleItem::create(['name' => 'Grapes']);

        $this->actingAs($this->user)
            ->delete(route('items.destroy', $item))
            ->assertRedirect();

        $this->assertSame(0, SimpleItem::count());
    }

    public function test_the_library_requires_authentication(): void
    {
        $item = SimpleItem::create(['name' => 'Grapes']);

        $this->get(route('items'))->assertRedirect('/login');
        $this->post(route('items.update', $item), ['breakdown' => 'x'])->assertRedirect('/login');
    }
}
