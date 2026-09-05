<?php

namespace App\Enums;

enum CategoryTag: string
{
    // Style and course
    case Soup = 'soup';
    case Salad = 'salad';
    case Keto = 'keto';
    case Tapas = 'tapas';
    case ComfortFood = 'comfort_food';
    case Holiday = 'holiday';
    case Grilling = 'grilling';
    case Appetizer = 'appetizer';
    case Dessert = 'dessert';
    case ReadyToEat = 'ready_to_eat';

    // Cuisine
    case Mexican = 'mexican';
    case Italian = 'italian';
    case Asian = 'asian';
    case Polynesian = 'polynesian';
    case American = 'american';

    public function label(): string
    {
        return match ($this) {
            self::ComfortFood => 'Comfort Food',
            self::ReadyToEat => 'Ready To Eat',
            default => ucfirst($this->value),
        };
    }

    /**
     * Cuisines and styles answer different questions, so the pickers group them
     * rather than presenting fifteen equal chips.
     */
    public function isCuisine(): bool
    {
        return in_array($this, self::cuisines(), true);
    }

    /** @return list<self> */
    public static function cuisines(): array
    {
        return [self::Mexican, self::Italian, self::Asian, self::Polynesian, self::American];
    }

    /** @return list<self> */
    public static function styles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $tag) => ! $tag->isCuisine(),
        ));
    }
}
