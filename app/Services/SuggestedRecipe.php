<?php

namespace App\Services;

use App\Models\Recipe;

/**
 * A recipe as offered by the suggestion ranker, carrying why it ranked where it
 * did so the UI can explain itself (spec 4.2.3 wants a "uses up: sour cream,
 * cilantro" tag on boosted suggestions).
 */
readonly class SuggestedRecipe
{
    /**
     * @param  list<string>  $usesUp  Names of at-risk ingredients this clears.
     */
    public function __construct(
        public Recipe $recipe,
        public int $score,
        public array $usesUp = [],
        public bool $repeatsYesterdaysProtein = false,
        public bool $cookedRecently = false,
    ) {}

    public function isBoosted(): bool
    {
        return $this->usesUp !== [];
    }

    /**
     * Short human explanation for the suggestion card.
     */
    public function reason(): ?string
    {
        if ($this->isBoosted()) {
            return 'uses up: '.implode(', ', $this->usesUp);
        }

        if ($this->repeatsYesterdaysProtein) {
            return 'same protein as yesterday';
        }

        if ($this->cookedRecently) {
            return 'made recently';
        }

        return null;
    }
}
