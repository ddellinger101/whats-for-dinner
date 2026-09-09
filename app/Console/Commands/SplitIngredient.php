<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Services\IngredientResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pulls apart an ingredient that is really two.
 *
 * The parser takes a recipe line at its word, so "sour cream and lime wedges"
 * became one ingredient. It is in the wrong aisle whichever aisle it is put
 * in, it can never be marked in stock truthfully, and the grocery list asks
 * for a thing no shop sells.
 *
 * The amount does not survive the split. "Sour cream and lime wedges: 1" says
 * nothing about how much of either, and inventing a number would be worse than
 * admitting the recipe never gave one.
 */
class SplitIngredient extends Command
{
    protected $signature = 'ingredients:split
        {name : The ingredient that is really several}
        {--into=* : The ingredients it should become}
        {--apply : Actually write the changes}';

    protected $description = 'Split an ingredient that is really two into its parts';

    public function handle(IngredientResolver $resolver): int
    {
        $source = Ingredient::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($this->argument('name')))])->first();

        if (! $source) {
            $this->error("No ingredient named \"{$this->argument('name')}\".");

            return self::FAILURE;
        }

        $parts = array_values(array_filter(array_map('trim', (array) $this->option('into'))));

        if (count($parts) < 2) {
            $this->error('Give at least two --into names, or there is nothing to split.');

            return self::FAILURE;
        }

        foreach ($parts as $part) {
            if (mb_strtolower($part) === mb_strtolower($source->name)) {
                $this->error("\"{$part}\" is the ingredient being split.");

                return self::FAILURE;
            }
        }

        $apply = (bool) $this->option('apply');

        $recipes = DB::table('recipe_ingredient')->where('ingredient_id', $source->id)->count();
        $flags = DB::table('inventory_flags')->where('ingredient_id', $source->id)->count();

        $this->newLine();
        $this->line("<options=bold>{$source->name}</> becomes ".implode(' + ', $parts));
        $this->line(sprintf(
            '  %d recipe%s, %s. The amount is dropped: the line never said how much of each.',
            $recipes,
            $recipes === 1 ? '' : 's',
            $flags ? 'a pantry entry, which goes' : 'no pantry entry',
        ));

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY RUN — nothing written. Re-run with --apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($source, $parts, $resolver) {
            $rows = DB::table('recipe_ingredient')->where('ingredient_id', $source->id)->get();

            foreach ($parts as $part) {
                $ingredient = $resolver->resolve($part);

                foreach ($rows as $row) {
                    // A recipe may already list one of the parts alongside the
                    // combined line; the pivot is unique per pair, so its own
                    // entry stands.
                    $exists = DB::table('recipe_ingredient')
                        ->where('recipe_id', $row->recipe_id)
                        ->where('ingredient_id', $ingredient->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('recipe_ingredient')->insert([
                        'id' => (string) Str::uuid(),
                        'recipe_id' => $row->recipe_id,
                        'ingredient_id' => $ingredient->id,
                        'quantity_per_serving' => null,
                        'unit' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Everything hanging off the combined row goes with it: a pantry
            // entry for a thing that was never one thing is not worth keeping.
            $source->delete();
        });

        $this->newLine();
        $this->info('Split into '.implode(' and ', $parts).'.');

        return self::SUCCESS;
    }
}
