<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date with no time component, stored as Y-m-d.
 *
 * Laravel's built-in `date` cast writes through the connection's datetime
 * format, so a value lands in the database as "2026-09-09 00:00:00". MySQL's
 * DATE column silently truncates that back to "2026-09-09", but SQLite stores
 * the string exactly as given. Equality lookups — firstOrCreate on a date, a
 * plain where() — therefore match in production and miss in tests, or worse,
 * the other way round.
 *
 * Writing the date-only form makes both engines agree.
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
