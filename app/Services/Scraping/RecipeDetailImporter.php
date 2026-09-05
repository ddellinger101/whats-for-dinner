<?php

namespace App\Services\Scraping;

use App\Enums\ImageStatus;
use App\Enums\IngredientsStatus;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Support\IngredientCategoryGuesser;
use App\Support\IngredientLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spec 4.7 — applies what the scraper found to a recipe.
 */
class RecipeDetailImporter
{
    private const MAX_IMAGE_BYTES = 5_000_000;

    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly RecipeScraper $scraper = new RecipeScraper,
        private readonly IngredientCategoryGuesser $categories = new IngredientCategoryGuesser,
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

        if (! $scraped) {
            return false;
        }

        if ($scraped->imageUrl && $recipe->canAcceptScrapedImage()) {
            $this->attachImage($recipe, $scraped->imageUrl);
        }

        if (! $scraped->hasIngredients()) {
            return false;
        }

        $this->attachIngredients($recipe, $scraped);

        return true;
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
        $name = Str::of($name)->squish()->limit(80, '')->value();

        // One recipe writes "1 onion", the next writes "2 onions". Left alone
        // those become two ingredients, and since use-by windows and use-up
        // matching are keyed per ingredient (spec 4.1, 4.2.3), a meal using
        // "onions" would not count as clearing the "onion" going off in the
        // fridge. Match every form; keep whichever spelling arrived first.
        $candidates = array_unique([
            mb_strtolower($name),
            mb_strtolower(Str::singular($name)),
            mb_strtolower(Str::plural($name)),
        ]);

        $existing = Ingredient::query()
            ->where(function ($query) use ($candidates) {
                foreach ($candidates as $candidate) {
                    $query->orWhereRaw('LOWER(name) = ?', [$candidate]);
                }
            })
            ->first();

        if ($existing) {
            return $existing;
        }

        $category = $this->categories->guess($name);

        return Ingredient::create([
            'name' => $name,
            'category' => $category,
            'shelf_life_days' => $category->defaultShelfLifeDays(),
        ]);
    }

    private function attachImage(Recipe $recipe, string $imageUrl): void
    {
        try {
            $response = Http::timeout(20)->get($imageUrl);

            if (! $response->successful()) {
                return;
            }

            $contentType = Str::before((string) $response->header('Content-Type'), ';');

            if (! in_array($contentType, self::ALLOWED_IMAGE_TYPES, true)) {
                return;
            }

            $body = $response->body();

            if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES) {
                return;
            }

            $extension = match ($contentType) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            $path = 'recipes/'.$recipe->id.'.'.$extension;
            Storage::disk('public')->put($path, $body);

            // Replacing an image leaves the old file behind otherwise, and these
            // accumulate one per re-import.
            if ($recipe->image_path && $recipe->image_path !== $path) {
                Storage::disk('public')->delete($recipe->image_path);
            }

            $recipe->update([
                'image_path' => $path,
                'image_source_url' => $imageUrl,
                'image_status' => ImageStatus::Scraped,
            ]);
        } catch (Throwable $e) {
            Log::info('Recipe image fetch failed', ['url' => $imageUrl, 'error' => $e->getMessage()]);
        }
    }
}
