<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Support\IngredientLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-reads ingredient names that arrived as prose.
 *
 * Recipe sites put section headings, footnotes and whole sentences in their
 * ingredient lists, and early versions of the parser stored them verbatim:
 * "Lemon juice: a squeeze of fresh lemon juice brightens the dish", "For the
 * caesar salad:", "15oz cans great northern beans".
 *
 * These are not merely untidy. An ingredient is the key the whole use-up
 * engine matches on, so a sentence is a row nothing will ever match, and
 * "15oz cans great northern beans" never lines up with the "great northern
 * beans" a later recipe records.
 */
class TidyIngredientNames extends Command
{
    protected $signature = 'ingredients:tidy
        {--apply : Actually write the changes}
        {--drop-headings : Also delete section headings such as "For the sauce"}';

    protected $description = 'Re-parse ingredient names that were stored as prose';

    public function handle(): int
    {
        $renames = [];
        $merges = [];
        $headings = [];

        foreach (Ingredient::orderBy('name')->get() as $ingredient) {
            if ($this->isHeading($ingredient->name)) {
                $headings[] = $ingredient;

                continue;
            }

            $cleaned = $this->clean($ingredient->name);

            if ($cleaned === '' || mb_strtolower($cleaned) === mb_strtolower($ingredient->name)) {
                continue;
            }

            $existing = Ingredient::whereRaw('LOWER(name) = ?', [mb_strtolower($cleaned)])
                ->whereKeyNot($ingredient->id)
                ->first();

            if ($existing) {
                $merges[] = ['from' => $ingredient, 'into' => $existing];
            } else {
                $renames[] = ['ingredient' => $ingredient, 'to' => $cleaned];
            }
        }

        foreach ($renames as $r) {
            $this->line(sprintf('  rename  %-44s -> %s', Str::limit($r['ingredient']->name, 42), $r['to']));
        }
        foreach ($merges as $m) {
            $this->line(sprintf('  merge   %-44s -> %s', Str::limit($m['from']->name, 42), $m['into']->name));
        }
        foreach ($headings as $h) {
            $this->line(sprintf('  heading %s', Str::limit($h->name, 60)));
        }

        $total = count($renames) + count($merges) + ($this->option('drop-headings') ? count($headings) : 0);

        if ($total === 0) {
            $this->newLine();
            $this->info('Nothing to tidy.');

            return self::SUCCESS;
        }

        $this->newLine();

        if (! $this->option('apply')) {
            $this->warn("{$total} would change. Re-run with --apply to write them.");

            if ($headings !== [] && ! $this->option('drop-headings')) {
                $this->line('Section headings are listed but left alone; add --drop-headings to remove them.');
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($renames, $merges, $headings) {
            foreach ($renames as $r) {
                $r['ingredient']->update(['name' => $r['to']]);
            }

            foreach ($merges as $m) {
                $this->mergeInto($m['from'], $m['into']);
            }

            if ($this->option('drop-headings')) {
                foreach ($headings as $heading) {
                    $this->detachEverywhere($heading);
                    $heading->delete();
                }
            }
        });

        $this->info("Tidied {$total}.");

        return self::SUCCESS;
    }

    private function clean(string $name): string
    {
        return IngredientLine::parse($name)->name;
    }

    /**
     * A heading names a part of the recipe rather than a thing to buy.
     */
    private function isHeading(string $name): bool
    {
        return (bool) preg_match('/^\s*(for the\b|toppings?$|garnish$|optional$)/iu', trim($name));
    }

    /**
     * Move everything pointing at the duplicate over to the row being kept.
     *
     * Both junction tables carry a unique constraint, so a row that would
     * collide is dropped rather than moved — the recipe already refers to the
     * ingredient it is being pointed at.
     */
    private function mergeInto(Ingredient $from, Ingredient $into): void
    {
        DB::table('recipe_ingredient')
            ->where('ingredient_id', $from->id)
            ->whereNotIn('recipe_id', function ($q) use ($into) {
                $q->select('recipe_id')->from('recipe_ingredient')->where('ingredient_id', $into->id);
            })
            ->update(['ingredient_id' => $into->id]);

        DB::table('ingredient_use_by_windows')
            ->where('ingredient_id', $from->id)
            ->whereNotIn('week_start_date', function ($q) use ($into) {
                $q->select('week_start_date')->from('ingredient_use_by_windows')->where('ingredient_id', $into->id);
            })
            ->update(['ingredient_id' => $into->id]);

        DB::table('grocery_list_items')
            ->where('ingredient_id', $from->id)
            ->update(['ingredient_id' => $into->id]);

        // inventory_flags is unique per ingredient; keep whichever row the
        // surviving ingredient already has.
        if (! DB::table('inventory_flags')->where('ingredient_id', $into->id)->exists()) {
            DB::table('inventory_flags')->where('ingredient_id', $from->id)->update(['ingredient_id' => $into->id]);
        }

        $this->detachEverywhere($from);
        $from->delete();
    }

    private function detachEverywhere(Ingredient $ingredient): void
    {
        DB::table('recipe_ingredient')->where('ingredient_id', $ingredient->id)->delete();
        DB::table('ingredient_use_by_windows')->where('ingredient_id', $ingredient->id)->delete();
        DB::table('inventory_flags')->where('ingredient_id', $ingredient->id)->delete();
        DB::table('grocery_list_items')->where('ingredient_id', $ingredient->id)->update(['ingredient_id' => null]);
    }
}
