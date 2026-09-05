<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Support\IngredientCategoryGuesser;
use Illuminate\Console\Command;

/**
 * Re-runs the category guesser over ingredients already stored.
 *
 * Categories are not cosmetic: each sets a shelf life, which opens a use-by
 * window, which the suggestion ranker boosts on. An ingredient filed under the
 * old rules keeps steering dinner from a wrong date until something re-reads
 * it — beer with six days would keep asking to be drunk.
 *
 * Reports by default and only writes when told to, because it will disagree
 * with corrections made by hand.
 */
class RecategoriseIngredients extends Command
{
    protected $signature = 'ingredients:recategorise
        {--apply : Actually write the changes}
        {--keep-shelf-life : Change the category but leave the shelf life as it is}
        {--refresh-dates : Recompute every pantry use-by date from current shelf lives}';

    protected $description = 'Re-derive ingredient categories and shelf lives';

    public function handle(IngredientCategoryGuesser $guesser): int
    {
        // Offered separately because the per-change refresh below only fires
        // for ingredients whose category moved in this run. Anything corrected
        // in an earlier run keeps its stale cached date forever otherwise —
        // which is exactly what happened.
        if ($this->option('refresh-dates')) {
            return $this->refreshAllDates();
        }

        $changes = [];

        foreach (Ingredient::orderBy('name')->get() as $ingredient) {
            $guessed = $guesser->guessOrNull($ingredient->name);

            if (! $guessed || $guessed === $ingredient->category) {
                continue;
            }

            $changes[] = [
                'ingredient' => $ingredient,
                'from' => $ingredient->category,
                'to' => $guessed,
            ];
        }

        if ($changes === []) {
            $this->info('Every ingredient already matches the current rules.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->line(sprintf(
                '  %-34s %s (%dd) -> %s (%dd)',
                $change['ingredient']->name,
                $change['from']->label(),
                $change['ingredient']->shelf_life_days,
                $change['to']->label(),
                $change['to']->defaultShelfLifeDays(),
            ));
        }

        $this->newLine();

        if (! $this->option('apply')) {
            $this->warn(count($changes).' would change. Re-run with --apply to write them.');

            return self::SUCCESS;
        }

        $refreshed = 0;

        foreach ($changes as $change) {
            $ingredient = $change['ingredient'];

            $ingredient->update([
                'category' => $change['to'],
            ] + ($this->option('keep-shelf-life')
                ? []
                : ['shelf_life_days' => $change['to']->defaultShelfLifeDays()]));

            if ($this->option('keep-shelf-life')) {
                continue;
            }

            // A pantry row caches its own expiry, taken from the shelf life at
            // the time it was bought. Leaving those alone would fix the
            // category and change nothing anyone can see: beer would still be
            // going off on Friday and bread would still keep for a year.
            $refreshed += InventoryFlag::query()
                ->where('ingredient_id', $ingredient->id)
                ->whereNotNull('acquired_on')
                ->get()
                ->each(fn (InventoryFlag $flag) => $flag->update([
                    'expires_on' => $flag->acquired_on->copy()->addDays($ingredient->fresh()->shelf_life_days),
                ]))
                ->count();
        }

        $this->info('Updated '.count($changes).' '.str('ingredient')->plural(count($changes)).'.');

        if ($refreshed > 0) {
            $this->info("Refreshed the use-by date on {$refreshed} pantry ".str('item')->plural($refreshed).'.');
        }

        return self::SUCCESS;
    }

    /**
     * Recompute every pantry expiry from when the item was acquired and what
     * its ingredient's shelf life says now. Dated from the acquisition, not
     * from today, so nothing gains or loses days it never had.
     */
    private function refreshAllDates(): int
    {
        $flags = InventoryFlag::query()
            ->with('ingredient')
            ->whereNotNull('acquired_on')
            ->get()
            ->filter(fn (InventoryFlag $flag) => $flag->ingredient !== null);

        $changed = 0;

        foreach ($flags as $flag) {
            $correct = $flag->acquired_on->copy()->addDays($flag->ingredient->shelf_life_days);

            if ($flag->expires_on?->equalTo($correct)) {
                continue;
            }

            $this->line(sprintf(
                '  %-34s %s -> %s',
                $flag->ingredient->name,
                $flag->expires_on?->format('j M Y') ?? 'none',
                $correct->format('j M Y'),
            ));

            $flag->update(['expires_on' => $correct]);
            $changed++;
        }

        $this->newLine();
        $this->info($changed === 0
            ? 'Every pantry date already matches its shelf life.'
            : "Refreshed {$changed} pantry ".str('date')->plural($changed).'.');

        return self::SUCCESS;
    }
}
