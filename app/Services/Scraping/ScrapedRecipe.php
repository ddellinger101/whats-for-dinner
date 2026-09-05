<?php

namespace App\Services\Scraping;

/**
 * What a recipe page yielded. Everything is optional: spec 4.7 expects a
 * meaningful fraction of the source links to give up nothing useful.
 */
readonly class ScrapedRecipe
{
    /**
     * @param  list<string>  $ingredientLines
     */
    public function __construct(
        public string $sourceUrl,
        public ?string $title = null,
        public ?string $imageUrl = null,
        public array $ingredientLines = [],
        public ?int $servings = null,
    ) {}

    public function hasIngredients(): bool
    {
        return $this->ingredientLines !== [];
    }

    public function hasAnything(): bool
    {
        return $this->hasIngredients() || $this->imageUrl !== null;
    }
}
