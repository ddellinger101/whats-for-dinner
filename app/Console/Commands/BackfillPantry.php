<?php

namespace App\Console\Commands;

use App\Enums\GroceryItemStatus;
use App\Models\GroceryListItem;
use App\Models\InventoryFlag;
use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Stocks the pantry from shopping that was already ticked off.
 *
 * Anything bought before the pantry existed, or before manual lines could
 * resolve to an ingredient, never reached it. Without this the feature looks
 * broken on a list that has been in use for weeks.
 */
class BackfillPantry extends Command
{
    protected $signature = 'pantry:backfill {--include-cleared : Also read lines that have been cleared off the list}';

    protected $description = 'Record already-purchased grocery lines into the pantry';

    public function handle(InventoryService $inventory): int
    {
        $items = GroceryListItem::query()
            ->when($this->option('include-cleared'), fn ($q) => $q->withTrashed())
            ->where('status', GroceryItemStatus::Purchased->value)
            ->orderBy('added_date')
            ->get();

        if ($items->isEmpty()) {
            $this->info('No purchased lines to read.');

            return self::SUCCESS;
        }

        // Counted per ingredient, not per line: buying bread twice updates one
        // pantry row, and reporting that as two stocked items is a lie.
        $stocked = [];
        $skipped = 0;

        foreach ($items as $item) {
            // Only ingredients with nothing on record are stocked. Re-running
            // must not keep stacking quantity onto rows already counted.
            $alreadyKnown = $item->ingredient_id
                && InventoryFlag::where('ingredient_id', $item->ingredient_id)->exists();

            if ($alreadyKnown) {
                $skipped++;

                continue;
            }

            $flag = $inventory->recordPurchase($item, $item->added_date);

            if ($flag) {
                if (! isset($stocked[$flag->ingredient_id])) {
                    $this->line("  <fg=green>+</> {$flag->ingredient->name} ({$flag->amountLabel()})");
                }
                $stocked[$flag->ingredient_id] = true;
            } else {
                $skipped++;
            }
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            ['Ingredients stocked', count($stocked)],
            ['Lines already known or unusable', $skipped],
        ]);

        return self::SUCCESS;
    }
}
