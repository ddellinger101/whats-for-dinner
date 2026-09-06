<?php

namespace App\Console\Commands;

use App\Enums\IngredientsStatus;
use App\Models\Recipe;
use App\Services\Scraping\RecipeDetailImporter;
use App\Services\Scraping\RecipeScraper;
use Illuminate\Console\Command;

/**
 * Backfills ingredients for the imported archive, rather than waiting for each
 * recipe to be selected for the first time (spec 4.7).
 */
class ScrapeRecipes extends Command
{
    protected $signature = 'recipes:scrape
        {--limit=25 : How many recipes to attempt in this run}
        {--recipe= : Scrape one recipe by name}
        {--missing-instructions : Re-read pages for recipes that have no method yet}
        {--retry : Include recipes already attempted and left without ingredients}';

    protected $description = 'Fetch ingredients, method and images from recipe links';

    public function handle(RecipeDetailImporter $importer, RecipeScraper $scraper): int
    {
        $query = Recipe::query()
            ->when($this->option('recipe'), fn ($q, $name) => $q->where('name', $name))
            // Instructions arrived after the archive was already scraped, so
            // this mode revisits recipes that have ingredients but no method.
            // Ingredients entered by hand are protected inside the importer.
            ->when(! $this->option('recipe') && $this->option('missing-instructions'), function ($q) {
                $q->where(function ($q) {
                    $q->whereNull('instructions')->orWhereJsonLength('instructions', 0);
                });
            })
            ->when(! $this->option('recipe') && ! $this->option('missing-instructions'), function ($q) {
                $q->where('ingredients_status', IngredientsStatus::NotYetAdded->value);
            });

        $candidates = $query->get()
            // Links that can never yield data are skipped up front so the run
            // spends its budget on pages that might actually work.
            ->filter(function (Recipe $recipe) use ($scraper) {
                $links = $recipe->recipe_links ?? [];

                return collect($links)->contains(fn ($url) => $scraper->isWorthTrying($url));
            })
            ->take((int) $this->option('limit'));

        if ($candidates->isEmpty()) {
            $this->info('Nothing to scrape.');

            return self::SUCCESS;
        }

        $this->line("Attempting {$candidates->count()} ".str('recipe')->plural($candidates->count()).'...');
        $this->newLine();

        $succeeded = 0;
        $failed = [];

        foreach ($candidates as $recipe) {
            $ok = $importer->import($recipe);

            $fresh = $recipe->fresh();

            if ($ok) {
                $succeeded++;
                $this->line(sprintf(
                    '  <fg=green>OK</>   %s (%d ingredients, %d steps)',
                    $recipe->name,
                    $fresh->ingredients()->count(),
                    count($fresh->instructions ?? []),
                ));
            } else {
                $failed[] = $recipe->name;
                $this->line("  <fg=yellow>--</>   {$recipe->name}");
            }
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            ['Ingredients imported', $succeeded],
            ['No usable data (manual entry needed)', count($failed)],
        ]);

        $withoutIngredients = Recipe::where('ingredients_status', IngredientsStatus::NotYetAdded->value)->count();
        $withoutSteps = Recipe::where(function ($q) {
            $q->whereNull('instructions')->orWhereJsonLength('instructions', 0);
        })->count();

        $this->line("{$withoutIngredients} recipes still without ingredients, {$withoutSteps} without a method.");

        return self::SUCCESS;
    }
}
