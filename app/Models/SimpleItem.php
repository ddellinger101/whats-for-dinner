<?php

namespace App\Models;

use App\Enums\MealTypeHint;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimpleItem extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['name', 'grocery_breakdown', 'meal_type_hint', 'breakdown_prompted'];

    protected $attributes = [
        'grocery_breakdown' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'grocery_breakdown' => 'array',
            'meal_type_hint' => MealTypeHint::class,
            'breakdown_prompted' => 'boolean',
        ];
    }

    public function mealComponents(): HasMany
    {
        return $this->hasMany(MealComponent::class);
    }

    /**
     * Spec 4.5: a compound item resolves to its saved breakdown; a single-concept
     * item (or one whose prompt was skipped) contributes its own name as one line.
     */
    public function groceryLines(): array
    {
        $breakdown = $this->grocery_breakdown ?? [];

        return $breakdown === [] ? [$this->name] : $breakdown;
    }

    public function isCompound(): bool
    {
        return ($this->grocery_breakdown ?? []) !== [];
    }
}
