<?php

namespace App\Jobs;

use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use App\Models\GroceryListItem;
use App\Models\RepeaterItem;
use App\Services\InventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;

/**
 * Takes a ticked-off line into the pantry, a while after it was ticked.
 *
 * Two reasons for the wait. Stocking the pantry means resolving a name to an
 * ingredient and writing a stock row, and doing that inside the request is
 * what made ticking things off feel slow in a shop. And a tick is easy to
 * misplace on a phone — the delay means an item ticked and immediately
 * unticked never reaches the pantry at all.
 */
class SettleGroceryPurchase implements ShouldQueue
{
    use Queueable;

    /** Long enough to undo a mistap, short enough to be done by the till. */
    public const DELAY_SECONDS = 30;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly string $itemId) {}

    /**
     * Two ticks in quick succession would otherwise both be in flight, and
     * each would add the shopping.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->itemId))->releaseAfter(30)];
    }

    public function handle(InventoryService $inventory): void
    {
        $item = GroceryListItem::find($this->itemId);

        if (! $item) {
            return;
        }

        // Unticked while we waited, which is the whole point of waiting.
        if ($item->status !== GroceryItemStatus::Purchased) {
            return;
        }

        // Already in the pantry. Buying more of something adds to what is
        // there, so running this twice would stock the same shopping twice.
        if ($item->settled_at !== null) {
            return;
        }

        $item->forceFill(['settled_at' => now()])->save();

        $inventory->recordPurchase($item);

        // Spec 4.6: buying a repeater is what restarts its clock.
        if ($item->source === GroceryItemSource::Repeater) {
            RepeaterItem::where('item_name', $item->item_name)->first()?->markPurchased(Carbon::today());
        }
    }
}
