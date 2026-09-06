<?php

namespace App\Models;

use App\Enums\ComponentType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class MealComponent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'meal_plan_entry_id', 'component_type', 'recipe_id',
        'simple_item_id', 'is_primary', 'servings_needed',
    ];

    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'is_primary' => 'boolean',
            'servings_needed' => 'integer',
        ];
    }

    public function mealPlanEntry(): BelongsTo
    {
        return $this->belongsTo(MealPlanEntry::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function simpleItem(): BelongsTo
    {
        return $this->belongsTo(SimpleItem::class);
    }

    /**
     * One line can be buying for several meals now, so the link runs through
     * the contributions rather than being a column on the line.
     */
    public function groceryListItems(): HasManyThrough
    {
        return $this->hasManyThrough(
            GroceryListItem::class,
            GroceryLineSource::class,
            'meal_component_id',
            'id',
            'id',
            'grocery_list_item_id',
        );
    }

    /**
     * What this component is called on the meal plan and on the calendar event.
     */
    public function displayName(): string
    {
        return $this->component_type === ComponentType::Recipe
            ? (string) $this->recipe?->name
            : (string) $this->simpleItem?->name;
    }

    /**
     * Spec 4.1 / 4.2: only primary recipe components drive use-by tracking,
     * protein rotation and use-up boosting.
     */
    public function drivesSuggestionLogic(): bool
    {
        return $this->is_primary && $this->component_type === ComponentType::Recipe;
    }
}
