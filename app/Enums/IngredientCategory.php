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
        };
    }

    /**
     * Everything but dry pantry goods gets a use-by window (spec 4.1).
     */
    public function isPerishable(): bool
    {
        return $this !== self::PantryDry;
    }
}
