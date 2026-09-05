<?php

namespace App\Services\Discovery;

use Illuminate\Support\Collection;

/**
 * A source of recipe ideas from outside the household's own library.
 *
 * Kept behind an interface because the choice of source is a trade rather than
 * a settled answer: a search engine reaches the whole web but returns pointers,
 * while a recipe database returns complete records from a much smaller pool.
 * Swapping or adding one should not touch the screens.
 */
interface RecipeSearchProvider
{
    public function isConfigured(): bool;

    /**
     * Human name for the source, shown so it is clear where results came from.
     */
    public function name(): string;

    /**
     * @return Collection<int, DiscoveredRecipe>
     */
    public function search(RecipeSearchQuery $query): Collection;
}
