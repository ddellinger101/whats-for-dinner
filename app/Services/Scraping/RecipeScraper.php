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
                $this->lastFetchRefused = false;

                return $scraped;
            }

            if ($this->lastFetchRefused) {
                $refused = true;
            }
        }

        // Reported only when every candidate refused; one blocked link among
        // several that simply had nothing is not the interesting case.
        $this->lastFetchRefused = $refused ?? false;

        return null;
    }

    /**
     * Whether the last fetch was refused outright rather than merely useless.
     *
     * Some large recipe sites block automated readers, answering 403 in a
     * fraction of a second. That is a deliberate refusal, not a transient
     * failure, so the app tells the user to paste the list themselves instead
     * of offering a retry that cannot work.
     */
    public bool $lastFetchRefused = false;

    public function scrape(string $url): ?ScrapedRecipe
    {
        $this->lastFetchRefused = false;

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
                // 401/403 is the site saying no; 429 is it saying not now.
                // Either way, fetching again will not help.
                $this->lastFetchRefused = in_array($response->status(), [401, 403, 429], true);

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
        $steps = [];
        $title = null;
        $image = null;
        $servings = null;

        if ($recipeNode !== null) {
            $ingredients = $this->ingredientLines($recipeNode);
            $steps = $this->instructionSteps($recipeNode['recipeInstructions'] ?? null);
            $title = is_string($recipeNode['name'] ?? null) ? trim($recipeNode['name']) : null;
            $image = $this->imageUrl($recipeNode['image'] ?? null);
            $servings = $this->servings($recipeNode['recipeYield'] ?? null);
        }

        // Even when there is no Recipe block, og:image usually still gives a
        // decent picture, which is worth having on its own.
        $image ??= $this->openGraphImage($html);

        if ($ingredients === [] && $steps === [] && $image === null) {
            return null;
        }

        return new ScrapedRecipe(
            sourceUrl: $url,
            title: $title,
            imageUrl: $image ? $this->absolutise($image, $url) : null,
            ingredientLines: $ingredients,
            steps: $steps,
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

    /**
     * Flatten schema.org recipeInstructions into ordered steps.
     *
     * The spec permits four shapes and sites use all of them: one blob of text
     * or HTML, a plain list of strings, a list of HowToStep objects, or
     * HowToSections each holding their own steps. Sections are flattened —
     * their names are useful for reading but would break a numbered list you
     * are trying to keep your place in.
     *
     * @return list<string>
     */
    private function instructionSteps(mixed $instructions): array
    {
        $steps = collect($this->flattenInstructions($instructions))
            ->map(fn (string $step) => $this->tidyStep($step))
            ->filter(fn (string $step) => $step !== '' && mb_strlen($step) < 2000)
            ->values();

        // A single blob means the site wrote its whole method as one string;
        // splitting it makes the difference between a wall of text and
        // something you can follow while cooking.
        if ($steps->count() === 1) {
            $steps = collect($this->splitBlob($steps->first()));
        }

        return $steps
            ->map(fn (string $step) => trim($step))
            ->filter(fn (string $step) => mb_strlen($step) > 2)
            ->take(60)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function flattenInstructions(mixed $node): array
    {
        if (is_string($node)) {
            return [$node];
        }

        if (! is_array($node)) {
            return [];
        }

        // A HowToSection holds its own list; a HowToStep holds its text.
        if (isset($node['itemListElement'])) {
            return $this->flattenInstructions($node['itemListElement']);
        }

        if (isset($node['text']) && is_string($node['text'])) {
            return [$node['text']];
        }

        // Some sites give a step only a name.
        if (isset($node['name']) && is_string($node['name']) && ! isset($node['itemListElement'])) {
            return [$node['name']];
        }

        $steps = [];

        foreach ($node as $child) {
            $steps = [...$steps, ...$this->flattenInstructions($child)];
        }

        return $steps;
    }

    /**
     * Strip the markup a step arrives wrapped in, keeping its line breaks.
     */
    private function tidyStep(string $step): string
    {
        $step = preg_replace('#<(br|/li|/p|/div)[^>]*>#i', "\n", $step) ?? $step;
        $step = html_entity_decode(strip_tags($step), ENT_QUOTES | ENT_HTML5);
        $step = preg_replace('/[ \t]+/u', ' ', $step) ?? $step;

        return trim($step);
    }

    /**
     * Break one blob of method into steps, on line breaks first and numbered
     * markers second. Sentences are deliberately not split on: "Bake at 350
     * degrees F. for 20 minutes" is one instruction, not two.
     *
     * @return list<string>
     */
    private function splitBlob(string $blob): array
    {
        $lines = collect(preg_split('/\R+/u', $blob) ?: [])
            ->map(fn ($line) => trim((string) $line))
            ->filter()
            ->values();

        if ($lines->count() > 1) {
            return $lines->all();
        }

        // "1. Do this 2. Do that" — split before a number that starts a step.
        $parts = preg_split('/(?<=[.!?])\s+(?=\d{1,2}[.)]\s)|(?=\bStep\s+\d+\b)/iu', $blob) ?: [];

        return collect($parts)
            ->map(fn ($part) => trim(preg_replace('/^\d{1,2}[.)]\s*/u', '', (string) $part) ?? (string) $part))
            ->filter()
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
