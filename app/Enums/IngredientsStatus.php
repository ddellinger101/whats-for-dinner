<?php

namespace App\Enums;

enum IngredientsStatus: string
{
    case NotYetAdded = 'not_yet_added';
    case AutoImported = 'auto_imported';
    case ManuallyEntered = 'manually_entered';

    public function label(): string
    {
        return match ($this) {
            self::NotYetAdded => 'Ingredients not added yet',
            self::AutoImported => 'Auto-imported from link',
            self::ManuallyEntered => 'Entered manually',
        };
    }

    /**
     * Whether this recipe can contribute to grocery lists and use-by windows.
     * Migrated recipes stay usable while missing ingredients (spec 4.7).
     */
    public function hasIngredients(): bool
    {
        return $this !== self::NotYetAdded;
    }
}
