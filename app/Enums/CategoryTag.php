<?php

namespace App\Enums;

enum CategoryTag: string
{
    case Soup = 'soup';
    case Salad = 'salad';
    case Keto = 'keto';
    case Tapas = 'tapas';
    case ComfortFood = 'comfort_food';
    case Holiday = 'holiday';
    case Grilling = 'grilling';
    case Appetizer = 'appetizer';
    case Dessert = 'dessert';

    public function label(): string
    {
        return match ($this) {
            self::ComfortFood => 'Comfort Food',
            default => ucfirst($this->value),
        };
    }
}
