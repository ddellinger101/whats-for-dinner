<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
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
        {--keep-shelf-life : Change the category but leave the shelf life as it is}';

    protected $description = 'Re-derive ingredient categories and shelf lives';

    public function handle(IngredientCategoryGuesser $guesser): int
    {
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

        foreach ($changes as $change) {
            $change['ingredient']->update([
                'category' => $change['to'],
            ] + ($this->option('keep-shelf-life')
                ? []
                : ['shelf_life_days' => $change['to']->defaultShelfLifeDays()]));
        }

        $this->info('Updated '.count($changes).' '.str('ingredient')->plural(count($changes)).'.');

        return self::SUCCESS;
    }
}
