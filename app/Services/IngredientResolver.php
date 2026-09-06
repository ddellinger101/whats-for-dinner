<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Support\IngredientCategoryGuesser;
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
}
