<?php

namespace App\Enums;

enum ImageStatus: string
{
    case None = 'none';
    case Scraped = 'scraped';
    case Uploaded = 'uploaded';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No image',
            self::Scraped => 'From recipe link',
            self::Uploaded => 'Photo taken',
        };
    }

    /**
     * A photo the user took themselves is never replaced by a later scrape.
     */
    public function isUserProvided(): bool
    {
        return $this === self::Uploaded;
    }
}
