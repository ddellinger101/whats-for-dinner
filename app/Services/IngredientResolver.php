<?php

namespace App\Services;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Support\IngredientCategoryGuesser;
use App\Support\PantryStaple;
use App\Support\PantryStaples;
use Illuminate\Support\Str;

/**
 * Finds or creates the one Ingredient row a given name should map to.
 *
 * Shared by the scraper and by manual entry: if these diverged, an ingredient
 * typed by hand would not match the same ingredient scraped from a link, and
 * use-by windows would silently stop lining up (spec 4.1, 4.2.3).
 */
class IngredientResolver
{
    public function __construct(
        private readonly IngredientCategoryGuesser $categories = new IngredientCategoryGuesser,
    ) {}

    public function resolve(string $name): Ingredient
    {
        $name = Str::of($name)->squish()->limit(80, '')->value();

        // An ingredient with no name matches nothing and helps nobody; it is
        // always a parsing fault upstream, and creating the row hides it.
        if ($name === '') {
            throw new \InvalidArgumentException('An ingredient needs a name.');
        }

        /*
         * The spice rack first. Recipes write one jar a dozen ways — "cumin",
         * "ground cumin", "cumin ground" — and without this each spelling
         * becomes its own row, only one of which is marked a staple, so the
         * others keep generating grocery lines for a jar already in the house.
         *
         * PantryStaples::match() refuses anything called fresh, so "fresh
         * thyme" falls through to the ordinary path below and gets its own
         * produce row, which is exactly what should happen.
         */
        if ($staple = PantryStaples::match($name)) {
            return $this->resolveStaple($staple);
        }

        // One recipe writes "1 onion", the next writes "2 onions". Match every
        // form and keep whichever spelling arrived first. Storing a forced
        // singular would be worse: Str::singular mangles mass nouns such as
        // molasses and greens.
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

    /**
     * The one row a rack jar maps to, under the label on the jar.
     *
     * An existing row of that name is adopted rather than duplicated, and
     * marked, since the archive already contains "Garlic powder" and
     * "Italian seasoning" from years of scraping.
     */
    private function resolveStaple(PantryStaple $staple): Ingredient
    {
        $existing = Ingredient::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($staple->name)])
            ->first();

        if ($existing) {
            if (! $existing->is_staple) {
                $existing->update(['is_staple' => true]);
            }

            return $existing;
        }

        return Ingredient::create([
            'name' => $staple->name,
            'category' => IngredientCategory::PantryDry,
            'shelf_life_days' => IngredientCategory::PantryDry->defaultShelfLifeDays(),
            'is_staple' => true,
        ]);
    }
}
