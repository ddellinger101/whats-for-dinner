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

    protected $fillable = ['date', 'slot', 'household_size_used', 'servings_manually_set'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'slot' => MealSlot::class,
            'household_size_used' => 'integer',
            'servings_manually_set' => 'boolean',
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

    /**
     * Pin this slot to a chosen number of servings. Holidays and guests break
     * the weekly rotation often enough that the manual figure has to survive any
     * later recalculation, so this also sets the flag that protects it.
     */
    public function setServings(int $servings): self
    {
        $this->update([
            'household_size_used' => max(1, $servings),
            'servings_manually_set' => true,
        ]);

        return $this;
    }

    /**
     * Hand the slot back to the weekly schedule.
     */
    public function useScheduledServings(): self
    {
        $this->update(['servings_manually_set' => false]);

        return $this;
    }
}
