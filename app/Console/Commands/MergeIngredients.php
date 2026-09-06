<?php

namespace App\Console\Commands;

use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\IngredientUseByWindow;
use App\Models\InventoryFlag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Folds duplicate ingredient rows into one.
 *
 * Years of scraping left the archive calling one thing several names — "Heavy
 * cream" and "Heavy whipping cream", "Chicken breasts" and "Boneless skinless
 * chicken breasts". Each spelling is its own row, so each keeps its own pantry
 * stock, its own use-by windows and its own grocery lines, and none of them
 * add up. The suggestion ranker then sees half the evidence it should.
 *
 * Everything pointing at the losing row is repointed at the kept one and the
 * loser is deleted. Three unique constraints make that less trivial than it
 * sounds — a recipe may hold both rows, a week may have a window for both, and
 * a pantry flag is one per ingredient — so each is resolved deliberately below
 * rather than left to a database error.
 *
 * Dry run unless --apply, because this is not reversible.
 */
class MergeIngredients extends Command
{
    protected $signature = 'ingredients:merge
        {into : The name to keep}
        {from* : One or more names to fold into it}
        {--apply : Actually write the changes}
        {--force : Merge even when one name says fresh and the other does not}';

    protected $description = 'Combine duplicate ingredient rows into one';

    public function handle(): int
    {
        $target = $this->findOrFail($this->argument('into'));

        if (! $target) {
            return self::FAILURE;
        }

        $sources = [];

        foreach ($this->argument('from') as $name) {
            $source = $this->findOrFail($name);

            if (! $source) {
                return self::FAILURE;
            }

            if ($source->is($target)) {
                $this->error("\"{$name}\" is the row being kept — nothing to merge.");

                return self::FAILURE;
            }

            if (! $this->freshnessAgrees($target, $source)) {
                return self::FAILURE;
            }

            $sources[] = $source;
        }

        $apply = (bool) $this->option('apply');

        foreach ($sources as $source) {
            $this->mergeOne($target, $source, $apply);
        }

        $this->newLine();

        if (! $apply) {
            $this->warn('DRY RUN — nothing written. Re-run with --apply.');

            return self::SUCCESS;
        }

        $this->info("Merged into {$target->fresh()->name}.");

        return self::SUCCESS;
    }

    /**
     * One of these names says fresh and the other does not, which usually
     * means they are genuinely different things — a jar of dried thyme and a
     * bunch of it. Merging them would quietly undo the distinction that keeps
     * fresh herbs on the grocery list, so it takes --force to say otherwise.
     */
    private function freshnessAgrees(Ingredient $target, Ingredient $source): bool
    {
        $saysFresh = fn (Ingredient $i) => (bool) preg_match('/\bfresh\b/iu', $i->name);

        if ($saysFresh($target) === $saysFresh($source) || $this->option('force')) {
            return true;
        }

        $this->error("\"{$source->name}\" and \"{$target->name}\" disagree about fresh.");
        $this->line('  A jar of the dried thing does not satisfy a recipe asking for the fresh one,');
        $this->line('  and merging them would put an end to that distinction. Pass --force if you');
        $this->line('  are sure these really are the same ingredient.');

        return false;
    }

    private function mergeOne(Ingredient $target, Ingredient $source, bool $apply): void
    {
        $this->newLine();
        $this->line("<options=bold>{$source->name}</> -> <options=bold>{$target->name}</>");

        // Counted before anything moves, so the dry run and the real run
        // report the same numbers.
        $sharedRecipes = DB::table('recipe_ingredient as a')
            ->join('recipe_ingredient as b', 'a.recipe_id', '=', 'b.recipe_id')
            ->where('a.ingredient_id', $source->id)
            ->where('b.ingredient_id', $target->id)
            ->count();

        $recipes = DB::table('recipe_ingredient')->where('ingredient_id', $source->id)->count();
        $grocery = GroceryListItem::withTrashed()->where('ingredient_id', $source->id)->count();
        $windows = IngredientUseByWindow::where('ingredient_id', $source->id)->count();
        $flag = InventoryFlag::where('ingredient_id', $source->id)->first();

        $this->line(sprintf(
            '  %d recipe%s (%d also list the kept row), %d grocery line%s, %d use-by window%s, %s',
            $recipes, $recipes === 1 ? '' : 's',
            $sharedRecipes,
            $grocery, $grocery === 1 ? '' : 's',
            $windows, $windows === 1 ? '' : 's',
            $flag ? 'a pantry entry' : 'no pantry entry',
        ));

        if ($target->category !== $source->category) {
            $this->warn("  Categories differ: {$source->category->label()} -> {$target->category->label()}");
        }

        if ($target->is_staple !== $source->is_staple) {
            $this->warn('  One is a spice-rack staple and the other is not; the merged row will be.');
        }

        if (! $apply) {
            return;
        }

        DB::transaction(function () use ($target, $source) {
            $this->moveRecipes($target, $source);
            $this->moveWindows($target, $source);
            $this->moveFlag($target, $source);

            GroceryListItem::withTrashed()
                ->where('ingredient_id', $source->id)
                ->update(['ingredient_id' => $target->id]);

            // Same ingredient, so if either was standing stock both were.
            if ($source->is_staple && ! $target->is_staple) {
                $target->update(['is_staple' => true]);
            }

            $source->delete();
        });
    }

    /**
     * A recipe may list both rows — "heavy cream" in the sauce and "heavy
     * whipping cream" in the topping — and the pivot is unique per pair. The
     * kept row's line wins, except where it has no amount and the loser does,
     * since a number beats no number.
     */
    private function moveRecipes(Ingredient $target, Ingredient $source): void
    {
        $sourceRows = DB::table('recipe_ingredient')->where('ingredient_id', $source->id)->get();

        foreach ($sourceRows as $row) {
            $existing = DB::table('recipe_ingredient')
                ->where('recipe_id', $row->recipe_id)
                ->where('ingredient_id', $target->id)
                ->first();

            if (! $existing) {
                DB::table('recipe_ingredient')
                    ->where('id', $row->id)
                    ->update(['ingredient_id' => $target->id]);

                continue;
            }

            if ($existing->quantity_per_serving === null && $row->quantity_per_serving !== null) {
                DB::table('recipe_ingredient')
                    ->where('id', $existing->id)
                    ->update([
                        'quantity_per_serving' => $row->quantity_per_serving,
                        'unit' => $row->unit,
                    ]);
            }

            DB::table('recipe_ingredient')->where('id', $row->id)->delete();
        }
    }

    /** Unique per ingredient and week, and recomputed anyway. */
    private function moveWindows(Ingredient $target, Ingredient $source): void
    {
        foreach (IngredientUseByWindow::where('ingredient_id', $source->id)->get() as $window) {
            // Compared as a date string, not as a Carbon: binding the object
            // serialises it with a time, which matches nothing in a DATE
            // column and let the clash through to the unique index.
            $clash = IngredientUseByWindow::where('ingredient_id', $target->id)
                ->whereDate('week_start_date', $window->week_start_date->toDateString())
                ->exists();

            $clash
                ? $window->delete()
                : $window->update(['ingredient_id' => $target->id]);
        }
    }

    /**
     * One pantry entry per ingredient, so two have to become one: the amounts
     * add up, and the sooner expiry wins because that is the one that matters.
     */
    private function moveFlag(Ingredient $target, Ingredient $source): void
    {
        $from = InventoryFlag::where('ingredient_id', $source->id)->first();

        if (! $from) {
            return;
        }

        $into = InventoryFlag::where('ingredient_id', $target->id)->first();

        if (! $into) {
            $from->update(['ingredient_id' => $target->id]);

            return;
        }

        $quantity = match (true) {
            $from->quantity === null || $into->quantity === null => null,
            default => round((float) $into->quantity + (float) $from->quantity, 3),
        };

        $expires = match (true) {
            $into->expires_on === null => $from->expires_on,
            $from->expires_on === null => $into->expires_on,
            default => $from->expires_on->lt($into->expires_on) ? $from->expires_on : $into->expires_on,
        };

        $into->update([
            'has_stock' => $into->has_stock || $from->has_stock,
            'quantity' => $quantity,
            'unit' => $into->unit ?? $from->unit,
            'expires_on' => $expires,
            'last_updated' => now(),
        ]);

        $from->delete();
    }

    private function findOrFail(string $name): ?Ingredient
    {
        $matches = Ingredient::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $this->error($matches->isEmpty()
            ? "No ingredient named \"{$name}\"."
            : "\"{$name}\" matches {$matches->count()} rows, which should not be possible.");

        if ($matches->isEmpty()) {
            $this->suggest($name);
        }

        return null;
    }

    /** A typo in a name is the likeliest failure, so offer the near misses. */
    private function suggest(string $name): void
    {
        $word = collect(preg_split('/\s+/u', mb_strtolower(trim($name))))
            ->sortByDesc(fn (string $w) => mb_strlen($w))
            ->first();

        if (! $word || mb_strlen($word) < 3) {
            return;
        }

        $near = Ingredient::whereRaw('LOWER(name) LIKE ?', ['%'.$word.'%'])
            ->orderBy('name')
            ->limit(10)
            ->pluck('name');

        if ($near->isEmpty()) {
            return;
        }

        $this->line('  Did you mean:');
        foreach ($near as $candidate) {
            $this->line("    - {$candidate}");
        }
    }
}
