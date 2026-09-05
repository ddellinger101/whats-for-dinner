<?php

namespace App\Enums;

enum ComponentType: string
{
    case Recipe = 'recipe';
    case SimpleItem = 'simple_item';

    public function label(): string
    {
        return match ($this) {
            self::Recipe => 'Recipe',
            self::SimpleItem => 'Simple item',
        };
    }
}
