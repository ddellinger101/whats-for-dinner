<?php

namespace App\Enums;

enum Rating: string
{
    case ThumbsUp = 'thumbs_up';
    case JustOk = 'just_ok';
    case ThumbsDown = 'thumbs_down';
    case Unrated = 'unrated';

    public function label(): string
    {
        return match ($this) {
            self::ThumbsUp => 'Thumbs up',
            self::JustOk => 'Just OK',
            self::ThumbsDown => 'Thumbs down',
            self::Unrated => 'Unrated',
        };
    }

    /**
     * Thumbs-down recipes are excluded from suggestions and blocked from fresh
     * selection until manually un-greyed (spec 4.2.1, 4.4).
     */
    public function isSelectable(): bool
    {
        return $this !== self::ThumbsDown;
    }

    /**
     * Tie-break weight for suggestion ranking (spec 4.2.5): thumbs_up above just_ok.
     */
    public function rankWeight(): int
    {
        return match ($this) {
            self::ThumbsUp => 2,
            self::Unrated => 1,
            self::JustOk => 0,
            self::ThumbsDown => -1,
        };
    }
}
