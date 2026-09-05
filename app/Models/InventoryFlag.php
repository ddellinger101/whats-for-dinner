<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the household actually has in, per ingredient.
 *
 * Started life as spec 3's boolean has-stock flag and grew quantities on
 * request (spec 6 had deferred those to v2). It is deliberately approximate:
 * a null quantity means "some, amount unknown", which is a genuinely different
 * answer from zero and the honest state for most of a real pantry.
 */
class InventoryFlag extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'ingredient_id', 'has_stock', 'quantity', 'unit',
        'acquired_on', 'expires_on', 'note', 'last_updated',
    ];

    protected $attributes = [
        'has_stock' => true,
    ];

    protected function casts(): array
    {
        return [
            'has_stock' => 'boolean',
            'quantity' => 'decimal:3',
            'acquired_on' => \App\Casts\DateOnly::class,
            'expires_on' => \App\Casts\DateOnly::class,
            'last_updated' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('has_stock', true);
    }

    /**
     * On hand and going off within the window — the set the suggestion ranker
     * should be trying to clear.
     */
    public function scopeExpiringWithin(Builder $query, int $days, ?Carbon $asOf = null): Builder
    {
        $asOf ??= Carbon::today();

        return $query->inStock()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $asOf->copy()->addDays($days)->toDateString());
    }

    public function daysLeft(?Carbon $asOf = null): ?int
    {
        if (! $this->expires_on) {
            return null;
        }

        return (int) ($asOf ?? Carbon::today())->startOfDay()->diffInDays($this->expires_on, false);
    }

    public function hasExpired(?Carbon $asOf = null): bool
    {
        $days = $this->daysLeft($asOf);

        return $days !== null && $days < 0;
    }

    /**
     * Take an amount out. A null quantity is left alone: the app does not know
     * how much was there, so it cannot know whether any is left, and guessing
     * would either strand things in the pantry or delete them wrongly.
     */
    public function consume(?float $amount): self
    {
        if ($amount === null || $this->quantity === null) {
            $this->update(['last_updated' => now()]);

            return $this;
        }

        $remaining = round((float) $this->quantity - $amount, 3);

        $this->update([
            'quantity' => max(0, $remaining),
            'has_stock' => $remaining > 0,
            'last_updated' => now(),
        ]);

        return $this;
    }

    /**
     * How much is here, for display. Null quantity reads as "in stock" rather
     * than a made-up number.
     */
    public function amountLabel(): string
    {
        if ($this->quantity === null) {
            return 'In stock';
        }

        $amount = rtrim(rtrim(number_format((float) $this->quantity, 2), '0'), '.');

        return trim($amount.' '.($this->unit ?? ''));
    }
}
