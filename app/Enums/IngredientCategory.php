<?php

namespace App\Enums;

enum IngredientCategory: string
{
    case Protein = 'protein';
    case Dairy = 'dairy';
    case Produce = 'produce';
    case PantryDry = 'pantry_dry';
    case JarredCanned = 'jarred_canned';
    case Frozen = 'frozen';
    case Condiment = 'condiment';
    // Neither existed until the pantry was stocked from a real list and showed
    // why they must: bread was claiming a year of shelf life as a dry good, and
    // beer was landing in produce with six days, which would have had the app
    // urging someone to drink it before it went off.
    case Bakery = 'bakery';
    case Beverage = 'beverage';

    public function label(): string
    {
        return match ($this) {
            self::Protein => 'Protein',
            self::Dairy => 'Dairy',
            self::Produce => 'Produce',
            self::PantryDry => 'Pantry / dry goods',
            self::JarredCanned => 'Jarred & canned',
            self::Frozen => 'Frozen',
            self::Condiment => 'Condiments',
            self::Bakery => 'Bakery',
            self::Beverage => 'Drinks',
        };
    }

    /**
     * Starting shelf-life values, since the source spreadsheet carries no
     * expiration data to migrate (spec 7). Editable per ingredient.
     */
    public function defaultShelfLifeDays(): int
    {
        return match ($this) {
            self::Protein => 3,
            self::Dairy => 12,
            self::Produce => 6,
            self::PantryDry => 365,
            self::JarredCanned => 21,
            self::Frozen => 180,
            self::Condiment => 90,
            self::Bakery => 7,
            self::Beverage => 180,
        };
    }

    /**
     * Which categories get a use-by window (spec 4.1).
     *
     * Drinks are excluded alongside dry goods: a sealed bottle is not a
     * use-it-up prompt, and treating it as one would push recipes that
     * "clear" beer to the top of the dinner suggestions.
     */
    public function isPerishable(): bool
    {
        return ! in_array($this, [self::PantryDry, self::Beverage], true);
    }
}
