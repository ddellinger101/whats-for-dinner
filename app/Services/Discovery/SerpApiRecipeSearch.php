<?php

namespace App\Services\Discovery;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Recipe discovery through SerpApi's Google results.
 *
 * Google's recipe block gives titles, links, ratings, images and ingredient
 * *names* — but no quantities and no method. That gap is filled at import time
 * by the scraper the app already uses on the household's own recipe links, so
 * a discovered recipe ends up with the same complete data as any other.
 *
 * Every call costs quota, so results are cached: the same search repeated, or
 * a back-navigation, must not spend another one.
 */
class SerpApiRecipeSearch implements RecipeSearchProvider
{
    private const ENDPOINT = 'https://serpapi.com/search';

    /**
     * Long enough that browsing back and forth is free, short enough that the
     * results are not stale by the next planning session.
     */
    private const CACHE_HOURS = 12;

    public function isConfigured(): bool
    {
        return filled(config('services.serpapi.key'));
    }

    public function name(): string
    {
        return 'Google';
    }

    /**
     * @return Collection<int, DiscoveredRecipe>
     */
    public function search(RecipeSearchQuery $query): Collection
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('No SerpApi key is configured on this server.');
        }

        if ($query->isEmpty()) {
            return collect();
        }

        $cached = Cache::get($query->cacheKey());

        if ($cached !== null) {
            return $this->hydrate($cached);
        }

        try {
            $response = Http::timeout(20)->get(self::ENDPOINT, [
                'engine' => 'google',
                'q' => $query->toSearchString(),
                'api_key' => config('services.serpapi.key'),
                'num' => 20,
                'hl' => 'en',
                'gl' => 'us',
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not reach the recipe search service.', 0, $e);
        }

        if ($response->status() === 401) {
            throw new RuntimeException('The SerpApi key was rejected.');
        }

        if ($response->status() === 429) {
            throw new RuntimeException('The recipe search quota for this month has run out.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($response->json('error') ?? 'The recipe search failed.');
        }

        $raw = $response->json('recipes_results') ?? [];

        if ($raw === []) {
            // Google only shows the recipe block for some queries. Falling back
            // to organic results would return blog index pages the scraper
            // cannot read, so an honest empty result is better.
            Log::info('Recipe search returned no recipe block', ['q' => $query->toSearchString()]);
        }

        Cache::put($query->cacheKey(), $raw, now()->addHours(self::CACHE_HOURS));

        return $this->hydrate($raw);
    }

    /**
     * @param  array<int, array<string, mixed>>  $raw
     * @return Collection<int, DiscoveredRecipe>
     */
    private function hydrate(array $raw): Collection
    {
        return collect($raw)
            ->filter(fn ($item) => is_array($item) && filled($item['link'] ?? null) && filled($item['title'] ?? null))
            ->map(fn (array $item) => new DiscoveredRecipe(
                title: (string) $item['title'],
                url: (string) $item['link'],
                source: isset($item['source']) ? (string) $item['source'] : null,
                thumbnail: isset($item['thumbnail']) ? (string) $item['thumbnail'] : null,
                rating: isset($item['rating']) ? (float) $item['rating'] : null,
                reviews: isset($item['reviews']) ? (int) $item['reviews'] : null,
                totalTime: isset($item['total_time']) ? (string) $item['total_time'] : null,
                ingredients: collect($item['ingredients'] ?? [])
                    ->filter(fn ($i) => is_string($i))
                    ->map(fn (string $i) => trim($i))
                    ->filter()
                    ->values()
                    ->all(),
            ))
            // The same recipe is often syndicated across several URLs.
            ->unique(fn (DiscoveredRecipe $recipe) => $recipe->normalisedUrl())
            ->values();
    }
}
