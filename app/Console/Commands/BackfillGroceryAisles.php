<?php

namespace App\Console\Commands;

use App\Models\GroceryListItem;
use App\Support\AisleGuesser;
use Illuminate\Console\Command;

/**
 * Fills in aisles for lines that predate the column.
 *
 * Without this every existing item lands in Other on first load, which looks
 * like the feature is broken rather than merely new.
 */
class BackfillGroceryAisles extends Command
{
    protected $signature = 'grocery:aisles {--all : Re-guess every line, not just the unfiled ones}';

    protected $description = 'Work out which aisle existing grocery lines belong in';

    public function handle(AisleGuesser $aisles): int
    {
        $items = GroceryListItem::query()
            ->withTrashed()
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('aisle'))
            ->with('ingredient')
            ->get();

        if ($items->isEmpty()) {
            $this->info('Nothing to file.');

            return self::SUCCESS;
        }

        $counts = [];

        foreach ($items as $item) {
            $aisle = $aisles->guess($item->item_name, $item->ingredient);
            $item->update(['aisle' => $aisle]);
            $counts[$aisle->label()] = ($counts[$aisle->label()] ?? 0) + 1;
        }

        arsort($counts);

        $this->line("Filed {$items->count()} ".str('line')->plural($items->count()).':');
        $this->table(['Aisle', 'Lines'], collect($counts)->map(fn ($n, $a) => [$a, $n])->values()->all());

        return self::SUCCESS;
    }
}
