<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Spec 4.7 / 7 — pulls structured recipe data off a page.
 *
 * Most recipe blogs publish schema.org Recipe as JSON-LD for search engines,
 * which is far more reliable than scraping markup. Pinterest, Instagram and
 * YouTube publish nothing usable, and the spec expects that: failure here is a
 * normal outcome that routes the user to manual entry, not an error.
 */
class RecipeScraper
{
    private const TIMEOUT_SECONDS = 15;

    /**
     * Hosts known to carry no recipe data. Skipped without a request so a
     * pointless fetch does not sit in the way of a manual-entry fallback.
     */
    private const HOPELESS_HOSTS = [
        'pin.it', 'pinterest.com', 'www.pinterest.com',
        'instagram.com', 'www.instagram.com',
        'youtube.com', 'www.youtube.com', 'youtu.be',
        'tiktok.com', 'www.tiktok.com',
        'facebook.com', 'www.facebook.com', 'fb.watch',
    ];

    public function isWorthTrying(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && ! in_array($host, self::HOPELESS_HOSTS, true);
    }

    /**
     * Try each link in order until one yields something (spec 4.7).
     *
     * @param  list<string>  $urls
     */
    public function scrapeFirstUsable(array $urls): ?ScrapedRecipe
    {
        foreach ($urls as $url) {
            if (! $this->isWorthTrying($url)) {
                continue;
            }

            $scraped = $this->scrape($url);

            if ($scraped?->hasAnything()) {
                return $scraped;
            }
        }

        return null;
    }

    public function scrape(string $url): ?ScrapedRecipe
    {
        try {
            $response = Http::withHeaders([
                // Plenty of recipe sites reject an obviously scripted client.
                'User-Agent' => 'Mozilla/5.0 (compatible; WhatsForDinner/1.0; +https://chef.dustindellinger.com)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            return $this->extract($response->body(), $url);
        } catch (Throwable $e) {
            // A dead link is an expected outcome, not an incident.
            Log::info('Recipe scrape failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function extract(string $html, string $url): ?ScrapedRecipe
    {
        $recipeNode = $this->findRecipeNode($html);

        $ingredients = [];
        $title = null;
        $image = null;
        $servings = null;

        if ($recipeNode !== null) {
            $ingredients = $this->ingredientLines($recipeNode);
            $title = is_string($recipeNode['name'] ?? null) ? trim($recipeNode['name']) : null;
            $image = $this->imageUrl($recipeNode['image'] ?? null);
            $servings = $this->servings($recipeNode['recipeYield'] ?? null);
        }

        // Even when there is no Recipe block, og:image usually still gives a
        // decent picture, which is worth having on its own.
        $image ??= $this->openGraphImage($html);

        if ($ingredients === [] && $image === null) {
            return null;
        }

        return new ScrapedRecipe(
            sourceUrl: $url,
            title: $title,
            imageUrl: $image ? $this->absolutise($image, $url) : null,
            ingredientLines: $ingredients,
            servings: $servings,
        );
    }

    /**
     * JSON-LD is regularly an array, or wrapped in an @graph, or has @type as a
     * list. Walk the whole structure rather than assuming a shape.
     *
     * @return array<string, mixed>|null
     */
    private function findRecipeNode(string $html): ?array
    {
        if (! preg_match_all(
            '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches,
        )) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);

            if (! is_array($decoded)) {
                continue;
            }

            if ($node = $this->searchForRecipe($decoded)) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>|null
     */
    private function searchForRecipe(array $data): ?array
    {
        $type = $data['@type'] ?? null;
        $types = array_map('mb_strtolower', array_filter((array) $type, 'is_string'));

        if (in_array('recipe', $types, true)) {
            return $data;
        }

        foreach ($data as $value) {
            if (is_array($value) && $found = $this->searchForRecipe($value)) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function ingredientLines(array $node): array
    {
        $raw = $node['recipeIngredient'] ?? $node['ingredients'] ?? [];

        return collect((array) $raw)
            // Not the 'is_string' string callable: Collection::filter passes
            // (value, key), and is_string takes exactly one argument.
            ->filter(fn ($line) => is_string($line))
            ->map(fn (string $line) => trim(html_entity_decode(strip_tags($line))))
            ->filter(fn (string $line) => $line !== '' && mb_strlen($line) < 200)
            ->unique()
            ->values()
            ->all();
    }

    private function imageUrl(mixed $image): ?string
    {
        return match (true) {
            is_string($image) => $image,
            // Sites publish image as a bare string, a list, or an ImageObject.
            is_array($image) && isset($image['url']) && is_string($image['url']) => $image['url'],
            is_array($image) && isset($image[0]) => $this->imageUrl($image[0]),
            default => null,
        };
    }

    private function servings(mixed $yield): ?int
    {
        $value = is_array($yield) ? ($yield[0] ?? null) : $yield;

        if (is_int($value)) {
            return $value > 0 && $value < 100 ? $value : null;
        }

        if (is_string($value) && preg_match('/\d+/', $value, $m)) {
            $n = (int) $m[0];

            return $n > 0 && $n < 100 ? $n : null;
        }

        return null;
    }

    private function openGraphImage(string $html): ?string
    {
        if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m)) {
            return $m[1];
        }

        if (preg_match('#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Protocol-relative and root-relative image URLs are common enough to be
     * worth handling rather than dropping the image.
     */
    private function absolutise(string $candidate, string $pageUrl): string
    {
        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
            return $candidate;
        }

        $scheme = parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($pageUrl, PHP_URL_HOST);

        if (str_starts_with($candidate, '//')) {
            return $scheme.':'.$candidate;
        }

        if ($host && str_starts_with($candidate, '/')) {
            return $scheme.'://'.$host.$candidate;
        }

        return $candidate;
    }
}
