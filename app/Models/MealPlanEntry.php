<?php

namespace App\Models;

use App\Enums\MealSlot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlanEntry extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['date', 'slot', 'household_size_used'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'slot' => MealSlot::class,
            'household_size_used' => 'integer',
        ];
    }

    public function components(): HasMany
    {
        return $this->hasMany(MealComponent::class);
    }

    /**
     * The one component a dinner slot is "about" (spec 3, MealComponent).
     * Breakfast and lunch slots commonly have none.
     */
    public function primaryComponent(): ?MealComponent
    {
        return $this->components->firstWhere('is_primary', true);
    }

    public function primaryRecipe(): ?Recipe
    {
        return $this->primaryComponent()?->recipe;
    }
}
