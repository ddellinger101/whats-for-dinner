<?php

namespace App\Services\Scraping;

use App\Enums\ImageStatus;
use App\Enums\IngredientsStatus;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\IngredientResolver;
use App\Services\RecipeImageStore;
use App\Support\IngredientLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spec 4.7 — applies what the scraper found to a recipe.
 */
class RecipeDetailImporter
{
    /**
     * True when the source site refused to be read, rather than simply having
     * nothing useful on the page. Read by the caller to explain which of those
     * happened.
     */
    public bool $lastFetchRefused = false;

    public function __construct(
        public readonly RecipeScraper $scraper = new RecipeScraper,
        // Shared with manual entry so both routes map a name to the same row.
        private readonly IngredientResolver $ingredients = new IngredientResolver,
        private readonly RecipeImageStore $images = new RecipeImageStore,
    ) {}

    /**
     * Returns true when the recipe ended up with ingredients.
     */
    public function import(Recipe $recipe): bool
    {
        $links = $recipe->recipe_links ?? [];

        if ($links === []) {
            return false;
        }

        $scraped = $this->scraper->scrapeFirstUsable($links);
        $this->lastFetchRefused = $this->scraper->lastFetchRefused;

        if (! $scraped) {
            return false;
        }

        return $this->apply($recipe, $scraped);
    }

    /**
     * Write an already-fetched page onto a recipe.
     *
     * Split out from import() so a caller that has to read the page first —
     * pasting a bare link, where even the title is unknown until it is
     * fetched — does not have to fetch it twice.
     */
    public function apply(Recipe $recipe, ScrapedRecipe $scraped): bool
    {
        if ($scraped->imageUrl && $recipe->canAcceptScrapedImage()) {
            $this->attachImage($recipe, $scraped->imageUrl);
        }

        // Never overwritten. A method someone typed or corrected by hand is
        // worth more than whatever the page says today.
        if ($scraped->hasSteps() && ! $recipe->hasInstructions()) {
            $recipe->update(['instructions' => $scraped->steps]);
            $recipe->refresh();
        }

        // Ingredients are replaced wholesale by a scrape, so a recipe whose
        // ingredients were entered by hand keeps them — re-reading a page for
        // its method must not quietly undo that work.
        $keepIngredients = $recipe->ingredients_status === IngredientsStatus::ManuallyEntered;

        if ($scraped->hasIngredients() && ! $keepIngredients) {
            $this->attachIngredients($recipe, $scraped);

            return true;
        }

        return $recipe->hasInstructions();
    }

    private function attachIngredients(Recipe $recipe, ScrapedRecipe $scraped): void
    {
        // The page's own yield is what its quantities are written for, so it has
        // to be adopted before dividing anything down to a per-serving figure.
        $servings = $scraped->servings ?: $recipe->base_servings;

        DB::transaction(function () use ($recipe, $scraped, $servings) {
            $attach = [];

            foreach ($scraped->ingredientLines as $line) {
                $parsed = IngredientLine::parse($line);

                if ($parsed->name === '') {
                    continue;
                }

                $ingredient = $this->resolveIngredient($parsed->name);

                // A recipe can list the same ingredient twice ("divided"); the
                // pivot is unique per pair, so first mention wins.
                if (isset($attach[$ingredient->id])) {
                    continue;
                }

                $attach[$ingredient->id] = [
                    'id' => (string) Str::uuid(),
                    'quantity_per_serving' => $parsed->quantity === null
                        ? null
                        : round($parsed->quantity / max(1, $servings), 4),
                    'unit' => $parsed->unit,
                ];
            }

            // Replace rather than merge: a re-import should reflect the source,
            // not accumulate stale rows from an earlier parse.
            $recipe->ingredients()->sync($attach);

            $recipe->update([
                'base_servings' => $servings,
                'ingredients_status' => IngredientsStatus::AutoImported,
            ]);
        });
    }

    private function resolveIngredient(string $name): Ingredient
    {
        return $this->ingredients->resolve($name);
    }

    /**
     * Shared with the three ways a household adds a picture by hand; the only
     * difference here is the status, which marks it as scraped so a photo the
     * household chose is never overwritten by one.
     */
    private function attachImage(Recipe $recipe, string $imageUrl): void
    {
        try {
            $this->images->storeFromUrl($recipe, $imageUrl, ImageStatus::Scraped);
        } catch (Throwable $e) {
            // A missing picture is not a reason to lose the recipe.
            Log::info('Recipe image fetch failed', ['url' => $imageUrl, 'error' => $e->getMessage()]);
        }
    }
}
