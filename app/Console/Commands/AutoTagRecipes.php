<?php

namespace App\Console\Commands;

use App\Enums\CategoryTag;
use App\Enums\ProteinType;
use App\Models\Recipe;
use App\Support\ProteinGuesser;
use App\Support\RecipeTagGuesser;
use Illuminate\Console\Command;

/**
 * Backfills category tags across the archive, which arrived with none.
 */
class AutoTagRecipes extends Command
{
    protected $signature = 'recipes:autotag
        {--dry-run : Show what would change without writing}
        {--replace : Replace existing tags instead of adding to them}
        {--skip-protein : Leave protein types alone}
        {--drop=* : Remove this tag where the guesser no longer infers it}
        {--limit=0 : Only process this many recipes}';

    protected $description = 'Infer category tags and protein for recipes from their names, ingredients and links';

    public function handle(RecipeTagGuesser $guesser, ProteinGuesser $proteins): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');
        $limit = (int) $this->option('limit');

        /*
         * Retiring a keyword leaves the tags it already wrote behind, because
         * this command only ever adds. --drop clears up after exactly one of
         * them: --replace would do it too, but by discarding every tag set by
         * hand along with it, which is not a trade worth making.
         */
        $drop = [];

        foreach ((array) $this->option('drop') as $value) {
            $tag = CategoryTag::tryFrom($value);

            if (! $tag) {
                $this->error("Not a tag: {$value}");

                return self::FAILURE;
            }

            $drop[] = $tag;
        }

        $recipes = Recipe::query()
            ->with('ingredients:id,name')
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->get();

        $changed = 0;
        $unchanged = 0;
        $stillEmpty = [];
        $tagCounts = [];
        $proteinFixes = [];

        foreach ($recipes as $recipe) {
            $guessed = $guesser->guess(
                $recipe->name,
                $recipe->ingredients->pluck('name')->all(),
                $recipe->recipe_links ?? [],
            );

            $existing = $recipe->category_tags->all();

            // Added to rather than replaced by default: a tag someone set by
            // hand is a decision, and a keyword list should not overrule it.
            $merged = $replace
                ? $guessed
                : collect($existing)->merge($guessed)->unique()->values()->all();

            // Only where the guesser has stopped inferring it: a recipe the
            // keyword still fits keeps the tag.
            $merged = collect($merged)
                ->reject(fn (CategoryTag $tag) => in_array($tag, $drop, true)
                    && ! in_array($tag, $guessed, true))
                ->values()
                ->all();

            // The keto flag and the keto tag are two views of one fact
            // (spec 3), so keep them consistent in both directions.
            $isKeto = collect($merged)->contains(CategoryTag::Keto) || $recipe->is_keto;

            if ($isKeto && ! collect($merged)->contains(CategoryTag::Keto)) {
                $merged[] = CategoryTag::Keto;
            }

            $before = collect($existing)->map->value->sort()->values()->all();
            $after = collect($merged)->map->value->sort()->values()->all();

            foreach ($after as $tag) {
                $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            }

            // Only recipes with no stated protein are reconsidered. The source
            // sheet filed anything unclassifiable under "Other", so dishes like
            // Burrito Skillet lost a protein they plainly have; an explicitly
            // set Chicken is a fact and stays untouched.
            $protein = $recipe->protein_type;

            if (! $this->option('skip-protein')
                && in_array($protein, [ProteinType::Vegetarian, ProteinType::None], true)) {
                $protein = $proteins->guess($recipe->name, $recipe->ingredients->pluck('name')->all());
            }

            $proteinChanged = $protein !== $recipe->protein_type;

            if ($proteinChanged) {
                $proteinFixes[] = "{$recipe->name}: {$recipe->protein_type->label()} -> {$protein->label()}";
            }

            if ($after === $before && $isKeto === $recipe->is_keto && ! $proteinChanged) {
                $unchanged++;

                if ($after === []) {
                    $stillEmpty[] = $recipe->name;
                }

                continue;
            }

            $changed++;

            if ($after === []) {
                $stillEmpty[] = $recipe->name;
            }

            if ($this->output->isVerbose()) {
                $this->line("  {$recipe->name}: ".(implode(', ', $after) ?: '(none)'));
            }

            if (! $dryRun) {
                $recipe->update([
                    'category_tags' => $after,
                    'is_keto' => $isKeto,
                    'protein_type' => $protein,
                ]);
            }
        }

        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing written' : 'Tagging complete');
        $this->table(['Result', 'Count'], [
            ['Recipes updated', $changed],
            ['Already correct', $unchanged],
            ['Still untagged', count($stillEmpty)],
        ]);

        arsort($tagCounts);
        $rows = [];
        foreach ($tagCounts as $tag => $count) {
            $rows[] = [CategoryTag::from($tag)->label(), $count];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['Tag', 'Recipes'], $rows);
        }

        if ($proteinFixes !== []) {
            $this->newLine();
            $this->line('Protein reassigned from ingredients — worth a glance:');
            foreach ($proteinFixes as $fix) {
                $this->line("  - {$fix}");
            }
        }

        if ($stillEmpty !== []) {
            $this->newLine();
            $this->warn('No tag could be inferred for these — they need a human:');
            foreach (array_slice($stillEmpty, 0, 40) as $name) {
                $this->line("  - {$name}");
            }
            if (count($stillEmpty) > 40) {
                $this->line('  ... and '.(count($stillEmpty) - 40).' more');
            }
        }

        return self::SUCCESS;
    }
}
