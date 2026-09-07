<?php

namespace App\Console\Commands;

use App\Models\InventoryFlag;
use App\Services\IngredientResolver;
use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Puts a list of things into the pantry in one go.
 *
 * For stocktaking: a shelf is photographed, the labels are read off, and the
 * lot goes in together. Doing that by hand through the pantry form is a form
 * submission per bottle.
 *
 * The dry run matters more than usual here. Every name goes through the shared
 * resolver, so it will happily create "Sriracha sauce" alongside the "Sriracha"
 * already in the archive — and merging those back together afterwards is work.
 * Reading which names are new before writing is how that gets caught.
 */
class AddToPantry extends Command
{
    protected $signature = 'pantry:add
        {name* : The things to add, as they should be named}
        {--apply : Actually write them}
        {--no-expiry : Leave the use-by date empty, for things that keep indefinitely}';

    protected $description = 'Add several things to the pantry at once';

    public function handle(IngredientResolver $resolver, InventoryService $inventory): int
    {
        $apply = (bool) $this->option('apply');
        $noExpiry = (bool) $this->option('no-expiry');

        $created = 0;
        $reused = 0;
        $already = 0;

        foreach ($this->argument('name') as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            // Resolved before anything is written, so the dry run can say which
            // names are about to become new ingredient rows.
            $existing = $resolver->find($name);
            $ingredient = $apply ? $resolver->resolve($name) : $existing;

            $inPantry = $ingredient
                && InventoryFlag::where('ingredient_id', $ingredient->id)->inStock()->exists();

            $verdict = match (true) {
                $inPantry => '<fg=yellow>already in the pantry</>',
                $existing !== null => '<fg=green>known ingredient</>',
                default => '<fg=blue>new ingredient</>',
            };

            $this->line(sprintf('  %-38s %s', $name, $verdict));

            match (true) {
                $inPantry => $already++,
                $existing !== null => $reused++,
                default => $created++,
            };

            if (! $apply || $inPantry || ! $ingredient) {
                continue;
            }

            $flag = $inventory->add($ingredient, null, null);

            // Ketchup does not go off on any timescale worth a use-by window,
            // and a shelf of sauces all turning at once would bury the things
            // that genuinely need using up.
            if ($noExpiry) {
                $flag->update(['expires_on' => null]);
            }
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            ['Matched an ingredient already known', $reused],
            ['New ingredients', $created],
            ['Already in the pantry', $already],
        ]);

        if (! $apply) {
            $this->warn('DRY RUN — nothing written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
