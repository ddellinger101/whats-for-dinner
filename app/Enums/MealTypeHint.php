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
    // Not in the spec's list, which assumed dinner always centres on a recipe.
    // Some whole dinners are not recipes at all — leftovers, a bought meal —
    // and calling those a "side" would file them wrongly in the quick-pick.
    case Dinner = 'dinner';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Breakfast',
            self::Lunch => 'Lunch',
            self::DinnerSide => 'Dinner side',
            self::Dinner => 'Dinner',
        };
    }
}
