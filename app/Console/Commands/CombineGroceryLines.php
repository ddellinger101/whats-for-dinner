<?php

namespace App\Console\Commands;

use App\Models\GroceryLineSource;
use App\Models\GroceryListItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Folds duplicate lines already on the list into one.
 *
 * New lines combine as they are added, but a list built before that still
 * holds a row per meal — two lines of extra virgin olive oil with the
 * shopper left to add them up.
 *
 * Only unpurchased lines are touched. A ticked-off line records what was
 * actually bought, and merging those would rewrite history.
 */
class CombineGroceryLines extends Command
{
    protected $signature = 'grocery:combine
        {--apply : Actually write the changes}';

    protected $description = 'Combine duplicate lines already on the grocery list';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $groups = GroceryListItem::query()
            ->needed()
            ->with('sources')
            ->orderBy('created_at')
            ->get()
            // Same thing and same unit. Two cups and three tablespoons are
            // both olive oil but they are not five of anything.
            ->groupBy(fn (GroceryListItem $item) => implode('|', [
                $item->ingredient_id ?: mb_strtolower(trim($item->item_name)),
                mb_strtolower((string) $item->unit),
            ]))
            ->filter(fn (Collection $lines) => $lines->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('Nothing on the list is duplicated.');

            return self::SUCCESS;
        }

        foreach ($groups as $lines) {
            $this->reportAndMerge($lines, $apply);
        }

        $this->newLine();

        if (! $apply) {
            $this->warn($groups->count().' would be combined. Re-run with --apply.');

            return self::SUCCESS;
        }

        $this->info('Combined '.$groups->count().' '.str('item')->plural($groups->count()).'.');

        return self::SUCCESS;
    }

    /** @param  Collection<int, GroceryListItem>  $lines */
    private function reportAndMerge(Collection $lines, bool $apply): void
    {
        $keep = $lines->first();
        $rest = $lines->slice(1);

        $quantities = $lines->pluck('quantity');
        $total = $quantities->contains(null) ? null : round((float) $quantities->sum(), 3);

        $this->line(sprintf(
            '  %-34s %d lines -> %s %s',
            $keep->item_name,
            $lines->count(),
            $total === null ? 'amount unknown' : rtrim(rtrim(number_format($total, 3), '0'), '.'),
            $keep->unit ?? '',
        ));

        if (! $apply) {
            return;
        }

        $plannedAmounts = $lines->pluck('planned_quantity');

        $planned = $plannedAmounts->contains(null)
            ? null
            : round((float) $plannedAmounts->sum(), 3);

        DB::transaction(function () use ($keep, $rest, $total, $planned) {
            foreach ($rest as $line) {
                foreach ($line->sources as $source) {
                    // A meal contributes to a line once, so a component that
                    // somehow reached both keeps the one already on the line
                    // being kept rather than tripping the unique index.
                    $alreadyThere = GroceryLineSource::where('grocery_list_item_id', $keep->id)
                        ->where('meal_component_id', $source->meal_component_id)
                        ->exists();

                    $alreadyThere
                        ? $source->delete()
                        : $source->update(['grocery_list_item_id' => $keep->id]);
                }

                // An ingredient link on any of them is worth keeping.
                if (! $keep->ingredient_id && $line->ingredient_id) {
                    $keep->ingredient_id = $line->ingredient_id;
                }

                $line->forceDelete();
            }

            $keep->fill(['quantity' => $total, 'planned_quantity' => $planned])->save();
        });
    }
}
