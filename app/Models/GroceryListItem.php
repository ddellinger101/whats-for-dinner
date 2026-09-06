<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\GroceryAisle;
use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroceryListItem extends Model
{
    // Soft deleted so the list keeps its own history: cleared lines still feed
    // the add-item autofill and the aisle guesser's memory.
    use HasFactory, HasUuids, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = [
        'item_name', 'quantity', 'planned_quantity', 'unit', 'aisle', 'source', 'status',
        'added_date', 'ingredient_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => GroceryItemSource::class,
            'status' => GroceryItemStatus::class,
            'aisle' => GroceryAisle::class,
            'added_date' => DateOnly::class,
            'quantity' => 'decimal:3',
            'planned_quantity' => 'decimal:3',
        ];
    }

    /**
     * Buying more than the week needs, on purpose — the pack size did not match
     * the recipe. The surplus is not waste; it lands in the pantry and the
     * ranker starts looking for something else to put it in.
     */
    public function isOverBought(): bool
    {
        return $this->quantity !== null
            && $this->planned_quantity !== null
            && (float) $this->quantity > (float) $this->planned_quantity;
    }

    public function surplus(): ?float
    {
        return $this->isOverBought()
            ? round((float) $this->quantity - (float) $this->planned_quantity, 3)
            : null;
    }

    /**
     * The planned meals this line is buying for. Several, since one line now
     * covers every meal in the week that wants the thing.
     */
    public function sources(): HasMany
    {
        return $this->hasMany(GroceryLineSource::class);
    }

    /**
     * What to call the reason this line exists — "for Tacos", or "for 3 meals"
     * once naming them all would be longer than the item.
     */
    public function reasonLabel(): ?string
    {
        $names = $this->sources
            ->map(fn (GroceryLineSource $s) => $s->mealComponent?->displayName())
            ->filter()
            ->unique()
            ->values();

        return match (true) {
            $names->isEmpty() => null,
            $names->count() === 1 => 'for '.$names->first(),
            $names->count() === 2 => 'for '.$names->join(' and '),
            default => "for {$names->count()} meals",
        };
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function scopeNeeded(Builder $query): Builder
    {
        return $query->where('status', GroceryItemStatus::Needed->value);
    }

    public function markPurchased(): void
    {
        $this->update(['status' => GroceryItemStatus::Purchased]);
    }
}
