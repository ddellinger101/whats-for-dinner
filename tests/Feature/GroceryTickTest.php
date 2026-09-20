<?php

namespace Tests\Feature;

use App\Enums\GroceryItemStatus;
use App\Jobs\SettleGroceryPurchase;
use App\Models\GroceryListItem;
use App\Models\InventoryFlag;
use App\Models\User;
use App\Services\GroceryListBuilder;
use App\Services\InventoryService;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ticking something off a grocery list.
 *
 * It is the one action done dozens of times in a shop, on a phone, so it has
 * to be instant. Stocking the pantry is deferred rather than done inside the
 * request — which also means a mistapped item, untapped again, never reaches
 * the pantry at all.
 */
class GroceryTickTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
    }

    private function item(string $name = 'Bananas'): GroceryListItem
    {
        return (new GroceryListBuilder)->addManual($name, 2.0, 'lb');
    }

    // ------------------------------------------------------------- the tick

    /** The request records the tick and nothing else. */
    public function test_ticking_queues_the_pantry_work_rather_than_doing_it(): void
    {
        Queue::fake();
        $item = $this->item();

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));

        $this->assertSame(GroceryItemStatus::Purchased, $item->fresh()->status);
        // Nothing reached the pantry inside the request.
        $this->assertSame(0, InventoryFlag::count());

        Queue::assertPushed(
            SettleGroceryPurchase::class,
            fn (SettleGroceryPurchase $job) => $job->itemId === $item->id
                // Half a minute out: long enough to undo a mistap.
                && now()->diffInSeconds($job->delay) >= 25,
        );
    }

    /** The page does not reload, so it asks for the counts instead. */
    public function test_the_tick_answers_with_the_new_counts(): void
    {
        Queue::fake();
        $this->item('Bananas');
        $milk = $this->item('Milk');

        $this->actingAs($this->user)
            ->postJson(route('grocery.toggle', $milk))
            ->assertOk()
            ->assertJson(['purchased' => true, 'needed' => 1, 'inCart' => 1]);
    }

    public function test_ticking_again_puts_it_back_on_the_list(): void
    {
        Queue::fake();
        $item = $this->item();

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));
        $this->actingAs($this->user)->post(route('grocery.toggle', $item));

        $this->assertSame(GroceryItemStatus::Needed, $item->fresh()->status);
    }

    // -------------------------------------------------------- the settling

    public function test_settling_stocks_the_pantry(): void
    {
        $item = $this->item();
        $item->update(['status' => GroceryItemStatus::Purchased]);

        (new SettleGroceryPurchase($item->id))->handle(app(InventoryService::class));

        $flag = InventoryFlag::firstOrFail();
        $this->assertSame('Bananas', $flag->ingredient->name);
        $this->assertEqualsWithDelta(2.0, (float) $flag->quantity, 0.001);
        $this->assertNotNull($item->fresh()->settled_at);
    }

    /** The whole reason for waiting: a mistap, undone, never lands. */
    public function test_an_item_unticked_before_the_delay_is_over_never_reaches_the_pantry(): void
    {
        Queue::fake();
        $item = $this->item();

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));
        $this->actingAs($this->user)->post(route('grocery.toggle', $item));

        // The job still runs; it finds the item back on the list and leaves.
        (new SettleGroceryPurchase($item->id))->handle(app(InventoryService::class));

        $this->assertSame(0, InventoryFlag::count());
        $this->assertNull($item->fresh()->settled_at);
    }

    /**
     * Buying more of something adds to what is there, so a job that ran twice
     * would stock the same shopping twice.
     */
    public function test_settling_twice_does_not_stock_it_twice(): void
    {
        $item = $this->item();
        $item->update(['status' => GroceryItemStatus::Purchased]);

        $service = app(InventoryService::class);
        (new SettleGroceryPurchase($item->id))->handle($service);
        (new SettleGroceryPurchase($item->id))->handle($service);

        $this->assertEqualsWithDelta(2.0, (float) InventoryFlag::firstOrFail()->quantity, 0.001);
    }

    /**
     * Unticking does not take the shopping back out — it never did — so the
     * mark stays and a tick, untick, tick cannot stock it a second time.
     */
    public function test_reticking_something_already_settled_does_not_stock_it_again(): void
    {
        Queue::fake();
        $item = $this->item();
        $item->update(['status' => GroceryItemStatus::Purchased]);

        $service = app(InventoryService::class);
        (new SettleGroceryPurchase($item->id))->handle($service);

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));
        $this->actingAs($this->user)->post(route('grocery.toggle', $item));
        (new SettleGroceryPurchase($item->id))->handle($service);

        $this->assertEqualsWithDelta(2.0, (float) InventoryFlag::firstOrFail()->quantity, 0.001);
    }

    /** A line cleared from the list before the job ran is simply gone. */
    public function test_a_deleted_item_settles_to_nothing(): void
    {
        $item = $this->item();
        $item->update(['status' => GroceryItemStatus::Purchased]);
        $id = $item->id;
        $item->forceDelete();

        (new SettleGroceryPurchase($id))->handle(app(InventoryService::class));

        $this->assertSame(0, InventoryFlag::count());
    }
}
