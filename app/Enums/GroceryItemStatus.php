<?php

namespace App\Enums;

enum GroceryItemStatus: string
{
    case Needed = 'needed';
    case Purchased = 'purchased';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
