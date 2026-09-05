<?php

namespace App\Models;

use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroceryListItem extends Model
{
    // Soft deleted so the list keeps its own history: cleared lines still feed
    // the add-item autofill and the aisle guesser's memory.
    use HasFactory, HasUuids, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $fillable = [
        'item_name', 'quantity', 'unit', 'aisle', 'source', 'status',
        'added_date', 'source_component_id', 'ingredient_id',
    ];

    protected function casts(): array
    {
        return [
            'source' => GroceryItemSource::class,
            'status' => GroceryItemStatus::class,
            'aisle' => \App\Enums\GroceryAisle::class,
            'added_date' => \App\Casts\DateOnly::class,
            'quantity' => 'decimal:3',
        ];
    }

    public function sourceComponent(): BelongsTo
    {
        return $this->belongsTo(MealComponent::class, 'source_component_id');
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
