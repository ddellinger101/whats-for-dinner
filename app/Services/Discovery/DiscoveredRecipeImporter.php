<?php

namespace App\Services\Discovery;

use App\Enums\IngredientsStatus;
use App\Enums\MealType;
use App\Enums\RecipeSource;
use App\Models\Recipe;
use App\Services\Scraping\RecipeDetailImporter;
use App\Support\ProteinGuesser;
use App\Support\RecipeTagGuesser;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a search result into a real recipe.
 *
 * A search result is only a pointer — names of ingredients, no quantities, no
 * method — so the full detail is pulled from the page itself by the same
 * scraper used on the household's own links. From that point the recipe is
 * indistinguishable from any other: it ranks, rates, expires and shops the same
 * way, which is the whole point of importing rather than bookmarking.
 */
class DiscoveredRecipeImporter
{
    /** Set when the source site refused to be read at all. */
    public bool $lastFetchRefused = false;

    public function __construct(
        public readonly RecipeDetailImporter $details = new RecipeDetailImporter,
        private readonly RecipeTagGuesser $tags = new RecipeTagGuesser,
        private readonly ProteinGuesser $proteins = new ProteinGuesser,
    ) {}

    /**
     * Already in the library? Matched on the normalised url rather than the
     * title, since the same recipe is syndicated under slightly different names.
     */
    public function existing(DiscoveredRecipe $discovered): ?Recipe
    {
        return Recipe::where('external_id', $discovered->externalId())->first();
    }

    public function import(DiscoveredRecipe $discovered): Recipe
    {
        if ($existing = $this->existing($discovered)) {
            return $existing;
        }

        $name = $this->uniqueName($discovered->cleanTitle());

        $recipe = Recipe::create([
            'name' => $name,
            'meal_type' => MealType::Dinner,
            'source' => RecipeSource::Discovered,
            'source_url' => $discovered->url,
            'source_name' => $discovered->sourceName(),
            'external_id' => $discovered->externalId(),
            'recipe_links' => [$discovered->url],
            'ingredients_status' => IngredientsStatus::NotYetAdded,
            'created_from_import' => false,
            // Guessed from what the search result already tells us, so the
            // recipe is filterable the moment it lands rather than after a
            // scrape that might fail.
            'protein_type' => $this->proteins->guess($name, $discovered->ingredients),
            'category_tags' => collect($this->tags->guess($name, $discovered->ingredients, [$discovered->url]))
                ->map->value->all(),
        ]);

        $recipe->update(['is_keto' => $recipe->fresh()->category_tags->contains(\App\Enums\CategoryTag::Keto)]);

        // Fetched inline rather than queued: someone who just tapped "add this"
        // expects a recipe, and an empty one filling in a minute later reads as
        // a failure. Wrapped, because a slow or broken page must still leave a
        // usable recipe behind with its link intact.
        try {
            $this->details->import($recipe);
            $this->lastFetchRefused = $this->details->lastFetchRefused;
        } catch (Throwable $e) {
            Log::info('Discovered recipe import could not fetch details', [
                'url' => $discovered->url,
                'error' => $e->getMessage(),
            ]);
        }

        return $recipe->fresh();
    }

    /**
     * Recipe names are unique in the library, and a discovered title can easily
     * collide with something already there.
     */
    private function uniqueName(string $name): string
    {
        $name = $name !== '' ? $name : 'Untitled recipe';

        if (! Recipe::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return $name;
        }

        for ($suffix = 2; $suffix <= 20; $suffix++) {
            $candidate = "{$name} ({$suffix})";

            if (! Recipe::whereRaw('LOWER(name) = ?', [mb_strtolower($candidate)])->exists()) {
                return $candidate;
            }
        }

        return $name.' ('.now()->format('j M').')';
    }
}
