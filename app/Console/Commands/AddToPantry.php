<?php

namespace App\Console\Commands;

use App\Models\InventoryFlag;
use App\Services\IngredientResolver;
use App\Services\InventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
        {--expires= : Use this date (Y-m-d) instead of the shelf life, for a printed one}
        {--no-expiry : Leave the use-by date empty, for things that keep indefinitely}';

    protected $description = 'Add several things to the pantry at once';

    public function handle(IngredientResolver $resolver, InventoryService $inventory): int
    {
        $apply = (bool) $this->option('apply');
        $noExpiry = (bool) $this->option('no-expiry');
        $expires = $this->option('expires');

        if ($expires !== null && $noExpiry) {
            $this->error('Pass one of --expires and --no-expiry, not both.');

            return self::FAILURE;
        }

        // Parsed before anything is written, so a typo is not discovered
        // halfway through a shelf.
        if ($expires !== null) {
            try {
                $expires = Carbon::createFromFormat('Y-m-d', $expires)->startOfDay();
            } catch (\Throwable) {
                $this->error("Not a date I can read: {$this->option('expires')}. Use Y-m-d.");

                return self::FAILURE;
            }

            $this->line("  Use by {$expires->format('j M Y')}.");
        }

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

            if (! $apply || ! $ingredient) {
                continue;
            }

            // Already here, so its amount and history are left alone — but a
            // date given on the command line is a statement about the thing
            // itself, and skipping the row entirely left half a shelf of
            // vinegar with a use-by date and half without.
            if ($inPantry) {
                if ($noExpiry || $expires !== null) {
                    InventoryFlag::where('ingredient_id', $ingredient->id)
                        ->update(['expires_on' => $expires]);
                }

                continue;
            }

            $flag = $inventory->add($ingredient, null, null);

            // A date off the packet beats one worked out from a category's
            // shelf life. Ketchup, meanwhile, does not go off on any timescale
            // worth a use-by window, and a shelf of sauces all turning at once
            // would bury the things that genuinely need using up.
            if ($noExpiry || $expires !== null) {
                $flag->update(['expires_on' => $expires]);
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
