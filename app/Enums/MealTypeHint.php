<?php

namespace App\Enums;

/**
 * Sorts a SimpleItem into the right quick-pick list. A hint, not a hard rule
 * (spec 3, SimpleItem) — any item can be added to any slot.
 */
enum MealTypeHint: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case DinnerSide = 'dinner_side';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Breakfast',
            self::Lunch => 'Lunch',
            self::DinnerSide => 'Dinner side',
        };
    }
}
