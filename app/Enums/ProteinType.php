<?php

namespace App\Enums;

enum ProteinType: string
{
    case Chicken = 'chicken';
    case Beef = 'beef';
    case Pork = 'pork';
    case Seafood = 'seafood';
    case Vegetarian = 'vegetarian';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Chicken => 'Chicken',
            self::Beef => 'Beef',
            self::Pork => 'Pork',
            self::Seafood => 'Seafood',
            self::Vegetarian => 'Vegetarian',
            self::None => 'No protein',
        };
    }

    /**
     * Protein types that participate in the day-to-day rotation check (spec 4.2.4).
     * "None" is excluded: a meatless meal should not suppress anything the next day.
     */
    public function rotates(): bool
    {
        return $this !== self::None;
    }
}
