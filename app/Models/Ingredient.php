<?php

namespace App\Models;

use App\Enums\IngredientCategory;
use App\Support\PantryStaples;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ingredient extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'category', 'shelf_life_days', 'default_unit', 'is_staple'];

    // Read back on a freshly created model, which a database default is not.
    protected $attributes = [
        'is_staple' => false,
    ];

    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'shelf_life_days' => 'integer',
            'is_staple' => 'boolean',
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
        // A jar on the spice rack outlasts any planning horizon, so treating it
        // as perishable would put it in the "use these up" list forever.
        return ! $this->is_staple && $this->category->isPerishable();
    }

    /**
     * Always in, so never worth adding to a list. Kept separate from
     * hasStock(): that is an observation about this week, this is a standing
     * fact about the ingredient.
     */
    public function isStaple(): bool
    {
        return (bool) $this->is_staple;
    }

    /**
     * True when a recipe naming this ingredient left the fresh-or-dried choice
     * open and fresh would be the better answer. The jar is assumed so the
     * line stays off the list; the recipe page says fresh would be better and
     * lets the cook decide.
     */
    public function betterFresh(): bool
    {
        return $this->is_staple && PantryStaples::betterFresh($this->name);
    }
}
