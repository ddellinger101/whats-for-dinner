<?php

namespace App\Enums;

enum MealType: string
{
    case Dinner = 'dinner';
    case Breakfast = 'breakfast';
    case Lunch = 'lunch';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
