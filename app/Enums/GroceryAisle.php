<?php

namespace App\Enums;

/**
 * Where an item lives in a shop.
 *
 * Deliberately separate from IngredientCategory, which answers a different
 * question: that one drives shelf life and use-by windows (spec 4.1), and its
 * categories do not match how a supermarket is laid out. "Protein" is one
 * shelf-life class but two aisles, and there is no bakery in it at all.
 */
enum GroceryAisle: string
{
    case Produce = 'produce';
    case Meat = 'meat';
    case Seafood = 'seafood';
    case Dairy = 'dairy';
    case Bakery = 'bakery';
    case Frozen = 'frozen';
    case Pantry = 'pantry';
    // Not in the original list, but beer and cider were already on the real
    // list with nowhere to go, and Other is meant to be a prompt rather than a
    // permanent home.
    case Drinks = 'drinks';
    // Spirits and mixers are their own trip in most shops, and often their own
    // shop entirely.
    case Bar = 'bar';
    case ReadyToEat = 'ready_to_eat';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ReadyToEat => 'Ready To Eat',
            default => ucfirst($this->value),
        };
    }

    /**
     * Roughly the order a shop is walked: fresh perimeter first, centre aisles
     * after, so the list reads top to bottom while moving through the store.
     *
     * @return list<self>
     */
    public static function inShoppingOrder(): array
    {
        return [
            self::Produce,
            self::Bakery,
            self::Meat,
            self::Seafood,
            self::Dairy,
            self::Frozen,
            self::Pantry,
            self::Drinks,
            self::Bar,
            self::ReadyToEat,
            self::Other,
        ];
    }
}
