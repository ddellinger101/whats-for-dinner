<?php

namespace App\Services;

use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Models\HouseholdSetting;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spec 4.2 — ranks recipes for a primary dinner slot.
 *
 * The spec gives an ordered list of concerns rather than a formula, so they are
 * expressed as weights here. The ordering that matters is encoded in their
 * relative size: one at-risk ingredient cleared outweighs a protein repeat,
 * because reducing waste is the point of the app (spec 1).
 */
class RecipeSuggestionRanker
{
    /** Per at-risk ingredient cleared. Dominates every other signal. */
    private const USE_UP_WEIGHT = 10;

    /** Spec 4.2.4 deprioritises, never blocks, a repeated protein. */
    private const PROTEIN_REPEAT_PENALTY = 6;

    /** Spec 4.2.5 tie-break: thumbs_up above just_ok. */
    private const RATING_WEIGHT = 2;

    /** Recently cooked is a mild nudge, overridden by any use-up boost. */
    private const RECENCY_PENALTY = 5;

    private const RECENCY_DAYS = 21;

    public function __construct(
        private readonly UseByWindowTracker $windows = new UseByWindowTracker,
    ) {}

    /**
     * @return Collection<int, SuggestedRecipe>
     */
    public function for(Carbon $date, int $limit = 25): Collection
    {
        $settings = HouseholdSetting::current();

        $openWindows = $this->windows->openWindows($date);
        $atRiskIds = $openWindows->keys()->all();
        $previousProtein = $this->previousDayProtein($date);

        $candidates = Recipe::query()
            ->selectable()                              // spec 4.2.1
            ->matchingDiet($settings->diet_mode)        // spec 4.2.2
            ->with('ingredients:id,name')
            ->get();

        return $candidates
            ->map(function (Recipe $recipe) use ($atRiskIds, $openWindows, $previousProtein, $date) {
                $clears = $recipe->ingredients
                    ->whereIn('id', $atRiskIds)
                    ->map(fn ($i) => $openWindows[$i->id]->ingredient->name ?? $i->name)
                    ->values()
                    ->all();

                $repeatsProtein = $previousProtein !== null
                    && $recipe->protein_type === $previousProtein;

                $cookedRecently = $recipe->last_cooked_on !== null
                    && $recipe->last_cooked_on->diffInDays($date, absolute: true) <= self::RECENCY_DAYS;

                $score = count($clears) * self::USE_UP_WEIGHT
                    + $recipe->rating->rankWeight() * self::RATING_WEIGHT;

                if ($repeatsProtein) {
                    $score -= self::PROTEIN_REPEAT_PENALTY;
                }

                // Spec 4.2.5: recency is set aside when the recipe is clearing
                // something at risk — waste reduction wins.
                if ($cookedRecently && $clears === []) {
                    $score -= self::RECENCY_PENALTY;
                }

                return new SuggestedRecipe(
                    recipe: $recipe,
                    score: $score,
                    usesUp: $clears,
                    repeatsYesterdaysProtein: $repeatsProtein,
                    cookedRecently: $cookedRecently,
                );
            })
            ->sort(function (SuggestedRecipe $a, SuggestedRecipe $b) {
                if ($a->score !== $b->score) {
                    return $b->score <=> $a->score;
                }

                // Equal score: prefer whatever has gone longest without being
                // made, treating never-cooked as the longest gap of all.
                $aDate = $a->recipe->last_cooked_on?->timestamp ?? 0;
                $bDate = $b->recipe->last_cooked_on?->timestamp ?? 0;

                return $aDate !== $bDate
                    ? $aDate <=> $bDate
                    : strcmp($a->recipe->name, $b->recipe->name);
            })
            ->take($limit)
            ->values();
    }

    /**
     * The protein of the primary component on the preceding day's dinner
     * (spec 4.2.4). Null when that slot is empty or held only simple items.
     */
    private function previousDayProtein(Carbon $date): ?ProteinType
    {
        $entry = MealPlanEntry::query()
            ->with('components.recipe')
            ->whereDate('date', $date->copy()->subDay()->toDateString())
            ->where('slot', MealSlot::Dinner->value)
            ->first();

        $protein = $entry?->primaryComponent()?->recipe?->protein_type;

        // A meatless main should not suppress anything the next day.
        return $protein?->rotates() ? $protein : null;
    }
}
