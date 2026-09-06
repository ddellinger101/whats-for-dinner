<?php

namespace App\Console\Commands;

use App\Models\GroceryLineSource;
use App\Models\GroceryListItem;
use App\Support\UnitConversion;
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
            // Same thing, in units that can be added. Tablespoons and cups of
            // one oil are one line; cloves and cans of anything are not.
            ->groupBy(fn (GroceryListItem $item) => implode('|', [
                $item->ingredient_id ?: mb_strtolower(trim($item->item_name)),
                UnitConversion::family($item->unit) ?? mb_strtolower((string) $item->unit),
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

        $amounts = fn (string $field) => $lines
            ->map(fn (GroceryListItem $l) => [
                $l->{$field} === null ? null : (float) $l->{$field},
                $l->unit,
            ])
            ->values()
            ->all();

        [$total, $unit] = UnitConversion::sum($amounts('quantity'));
        [$planned] = UnitConversion::sum($amounts('planned_quantity'));

        $this->line(sprintf(
            '  %-34s %d lines -> %s %s',
            $keep->item_name,
            $lines->count(),
            $total === null ? 'amount unknown' : rtrim(rtrim(number_format($total, 3), '0'), '.'),
            $unit ?? '',
        ));

        if (! $apply) {
            return;
        }

        DB::transaction(function () use ($keep, $rest, $total, $planned, $unit) {
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

            $keep->fill([
                'quantity' => $total,
                'planned_quantity' => $planned,
                'unit' => $unit ?? $keep->unit,
            ])->save();
        });
    }
}
