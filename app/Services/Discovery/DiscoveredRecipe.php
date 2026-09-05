<?php

namespace App\Services\Discovery;

use Illuminate\Support\Str;

/**
 * One recipe as it comes back from a search, before anything is imported.
 *
 * Deliberately thin. A search result is a pointer, not a recipe: the full
 * ingredient list with quantities is fetched from the page itself at import
 * time by the same scraper the rest of the app uses.
 */
readonly class DiscoveredRecipe
{
    /**
     * @param  list<string>  $ingredients  Names only — search results carry no quantities.
     */
    public function __construct(
        public string $title,
        public string $url,
        public ?string $source = null,
        public ?string $thumbnail = null,
        public ?float $rating = null,
        public ?int $reviews = null,
        public ?string $totalTime = null,
        public array $ingredients = [],
    ) {}

    /**
     * Stable id for a result, so an imported recipe can be recognised again on
     * a later search without depending on the title matching exactly.
     */
    public function externalId(): string
    {
        return 'url:'.sha1($this->normalisedUrl());
    }

    /**
     * Tracking parameters differ between searches for the same page, so they
     * are stripped before the url is used as an identity.
     */
    public function normalisedUrl(): string
    {
        $parts = parse_url($this->url);

        if (! $parts || ! isset($parts['host'])) {
            return rtrim($this->url, '/');
        }

        $scheme = $parts['scheme'] ?? 'https';
        $path = rtrim($parts['path'] ?? '', '/');

        return mb_strtolower($scheme.'://'.$parts['host'].$path);
    }

    public function sourceName(): string
    {
        return $this->source ?: (parse_url($this->url, PHP_URL_HOST) ?: 'the web');
    }

    /**
     * A tidy title: search results often carry the site name and stray
     * separators, which would end up as the recipe's name.
     */
    public function cleanTitle(): string
    {
        $title = preg_replace('/\s*[|–—-]\s*[^|–—-]{0,40}$/u', '', trim($this->title)) ?? $this->title;
        $title = preg_replace('/\s*\(.*?recipe.*?\)\s*/iu', ' ', $title) ?? $title;

        return Str::of($title)->squish()->limit(120, '')->value() ?: trim($this->title);
    }
}
