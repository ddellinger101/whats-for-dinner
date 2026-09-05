<?php

namespace App\Enums;

/**
 * Where a recipe came from.
 *
 * Not the same question as ingredients_status, which is about whether the
 * ingredients are known. A discovered recipe needs attribution shown and a
 * link kept; one typed in by hand needs neither.
 */
enum RecipeSource: string
{
    case Spreadsheet = 'spreadsheet';
    case Manual = 'manual';
    case Discovered = 'discovered';

    public function label(): string
    {
        return match ($this) {
            self::Spreadsheet => 'From your archive',
            self::Manual => 'Added by hand',
            self::Discovered => 'Found online',
        };
    }

    /**
     * Search results are someone else's work, so the site that published them
     * is credited wherever the recipe is shown.
     */
    public function needsAttribution(): bool
    {
        return $this === self::Discovered;
    }
}
