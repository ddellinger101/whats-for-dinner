<?php

namespace App\Services\Discovery;

use App\Enums\CategoryTag;
use App\Enums\ProteinType;
use Illuminate\Support\Str;

/**
 * What the household is looking for.
 *
 * A search engine has no filter parameters for cuisine or diet, so these are
 * folded into the query text. That is a real limitation compared with a recipe
 * database's structured filters, and the reason the terms are spelled out here
 * rather than hidden in the provider.
 */
readonly class RecipeSearchQuery
{
    public function __construct(
        public string $text = '',
        public ?ProteinType $protein = null,
        public ?CategoryTag $tag = null,
        public bool $ketoOnly = false,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '' && ! $this->protein && ! $this->tag && ! $this->ketoOnly;
    }

    /**
     * The phrase actually sent to the search engine.
     */
    public function toSearchString(): string
    {
        $parts = [];

        if ($this->ketoOnly) {
            $parts[] = 'keto';
        }

        if ($this->tag) {
            $parts[] = mb_strtolower($this->tag->label());
        }

        // "No protein" and "vegetarian" are the same request to a search engine,
        // and "other" would just be noise, so neither is passed through raw.
        if ($this->protein && $this->protein !== ProteinType::None) {
            $parts[] = $this->protein === ProteinType::Vegetarian
                ? 'vegetarian'
                : mb_strtolower($this->protein->label());
        }

        if (trim($this->text) !== '') {
            $parts[] = trim($this->text);
        }

        // "recipe" keeps Google on recipe pages rather than restaurants or
        // shopping results, which is what surfaces the structured block.
        $parts[] = 'recipe';

        return Str::of(implode(' ', $parts))->squish()->limit(200, '')->value();
    }

    /**
     * Cache key for this exact search, so repeating it costs no quota.
     */
    public function cacheKey(): string
    {
        return 'discover:'.sha1($this->toSearchString());
    }
}
