<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned meal's share of a grocery line.
 *
 * Three recipes wanting olive oil make one line and three of these, so
 * dropping one meal can take its share back out without guessing at the rest.
 */
class GroceryLineSource extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['grocery_list_item_id', 'meal_component_id', 'quantity', 'unit'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    public function groceryListItem(): BelongsTo
    {
        return $this->belongsTo(GroceryListItem::class);
    }

    public function mealComponent(): BelongsTo
    {
        return $this->belongsTo(MealComponent::class);
    }
}
