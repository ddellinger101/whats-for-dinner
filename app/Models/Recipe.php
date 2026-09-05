<?php

namespace App\Models;

use App\Enums\CategoryTag;
use App\Enums\IngredientsStatus;
use App\Enums\MealType;
use App\Enums\ProteinType;
use App\Enums\Rating;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'protein_type', 'meal_type', 'category_tags', 'is_keto',
        'recipe_links', 'base_servings', 'rating', 'times_made',
        'ingredients_status', 'notes', 'created_from_import', 'last_cooked_on',
    ];

    protected $attributes = [
        'category_tags' => '[]',
        'recipe_links' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'protein_type' => ProteinType::class,
            'meal_type' => MealType::class,
            'category_tags' => AsEnumCollection::class.':'.CategoryTag::class,
            'recipe_links' => 'array',
            'rating' => Rating::class,
            'ingredients_status' => IngredientsStatus::class,
            'is_keto' => 'boolean',
            'created_from_import' => 'boolean',
            'base_servings' => 'integer',
            'times_made' => 'integer',
            'last_cooked_on' => 'date',
        ];
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'recipe_ingredient')
            ->withPivot(['quantity_per_serving', 'unit'])
            ->withTimestamps();
    }

    public function mealComponents(): HasMany
    {
        return $this->hasMany(MealComponent::class);
    }

    /**
     * Spec 4.2.1: thumbs-down recipes are excluded from suggestions and blocked
     * from fresh selection, but stay visible in the recipe browser.
     */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('rating', '!=', Rating::ThumbsDown->value);
    }

    /**
     * Spec 4.2.2: hide off-diet recipes from suggestions while a diet mode is on.
     * Passing null leaves the set untouched.
     */
    public function scopeMatchingDiet(Builder $query, ?string $dietMode): Builder
    {
        return $dietMode === 'keto' ? $query->where('is_keto', true) : $query;
    }

    public function isSelectable(): bool
    {
        return $this->rating->isSelectable();
    }

    /**
     * Spec 4.3: round the multiplier up, not the final amounts — a recipe for 4
     * feeding 5 scales as if serving 6.
     */
    public function servingMultiplierFor(int $householdSize): int
    {
        return max(1, (int) ceil($householdSize / max(1, $this->base_servings)));
    }
}
