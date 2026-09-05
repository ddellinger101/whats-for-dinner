<?php

namespace App\Enums;

enum MealSlot: string
{
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';
    case Dinner = 'dinner';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Display order for the week grid (spec 5, Meal Plan).
     */
    public static function ordered(): array
    {
        return [self::Breakfast, self::Lunch, self::Dinner];
    }

    /**
     * Only dinner runs the ranked suggestion engine (spec 4.2); breakfast and
     * lunch default to the simple-item quick-pick library (spec 4.5).
     */
    public function usesSuggestionEngine(): bool
    {
        return $this === self::Dinner;
    }
}
