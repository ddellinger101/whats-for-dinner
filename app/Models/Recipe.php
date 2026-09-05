<?php

namespace App\Models;

use App\Enums\CategoryTag;
use App\Enums\ImageStatus;
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
        'image_path', 'image_source_url', 'image_status',
    ];

    /**
     * Mirrors the column defaults. A database default is only applied on INSERT
     * and is not reflected back onto the model instance, so without these a
     * freshly created Recipe has null enum attributes and any method called on
     * one fatals.
     */
    protected $attributes = [
        'category_tags' => '[]',
        'recipe_links' => '[]',
        'protein_type' => 'none',
        'meal_type' => 'dinner',
        'rating' => 'unrated',
        'ingredients_status' => 'not_yet_added',
        'image_status' => 'none',
        'is_keto' => false,
        'created_from_import' => false,
        'base_servings' => 4,
        'times_made' => 0,
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
            'image_status' => ImageStatus::class,
            'is_keto' => 'boolean',
            'created_from_import' => 'boolean',
            'base_servings' => 'integer',
            'times_made' => 'integer',
            'last_cooked_on' => \App\Casts\DateOnly::class,
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

    public function hasImage(): bool
    {
        return filled($this->image_path);
    }

    public function imageUrl(): ?string
    {
        return $this->hasImage() ? asset('storage/'.$this->image_path) : null;
    }

    /**
     * A photo taken in the kitchen outranks anything scraped later, so a
     * re-import never overwrites the user's own picture.
     */
    public function canAcceptScrapedImage(): bool
    {
        return ! $this->image_status->isUserProvided();
    }
}
