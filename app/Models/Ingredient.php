<?php

namespace App\Models;

use App\Enums\IngredientCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ingredient extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'category', 'shelf_life_days', 'default_unit'];

    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'shelf_life_days' => 'integer',
        ];
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'recipe_ingredient')
            ->withPivot(['quantity_per_serving', 'unit'])
            ->withTimestamps();
    }

    public function inventoryFlag(): HasOne
    {
        return $this->hasOne(InventoryFlag::class);
    }

    public function useByWindows(): HasMany
    {
        return $this->hasMany(IngredientUseByWindow::class);
    }

    /**
     * Spec 4.6: an ingredient flagged as in stock is skipped when auto-generating
     * grocery items. Absence of a flag means "not in stock".
     */
    public function hasStock(): bool
    {
        return (bool) $this->inventoryFlag?->has_stock;
    }

    public function isPerishable(): bool
    {
        return $this->category->isPerishable();
    }
}
