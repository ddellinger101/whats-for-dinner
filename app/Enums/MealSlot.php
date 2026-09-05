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

    /**
     * When the calendar event lands (spec 4.8). Timed rather than all-day, so a
     * week of meals reads as a schedule instead of three banners per day.
     *
     * @return array{0: int, 1: int} Hour and minute.
     */
    public function defaultTime(): array
    {
        return match ($this) {
            self::Breakfast => [8, 0],
            self::Lunch => [12, 30],
            self::Dinner => [18, 0],
        };
    }

    public function durationMinutes(): int
    {
        return $this === self::Dinner ? 60 : 30;
    }
}
