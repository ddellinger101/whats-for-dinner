<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class IngredientUseByWindow extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['ingredient_id', 'week_start_date', 'purchase_date', 'expires_on'];

    protected function casts(): array
    {
        return [
            'week_start_date' => \App\Casts\DateOnly::class,
            'purchase_date' => \App\Casts\DateOnly::class,
            'expires_on' => \App\Casts\DateOnly::class,
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * Spec 4.2.3: only windows still open are eligible to boost a suggestion.
     */
    public function scopeOpen(Builder $query, ?Carbon $asOf = null): Builder
    {
        return $query->whereDate('expires_on', '>=', $asOf ?? Carbon::today());
    }

    public function scopeForWeek(Builder $query, Carbon $weekStart): Builder
    {
        return $query->whereDate('week_start_date', $weekStart->toDateString());
    }

    public function isOpen(?Carbon $asOf = null): bool
    {
        return $this->expires_on->greaterThanOrEqualTo($asOf ?? Carbon::today());
    }
}
