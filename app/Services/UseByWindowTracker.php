<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use App\Models\Ingredient;
use App\Models\IngredientUseByWindow;
use App\Models\MealComponent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spec 4.1 — tracks how long each perishable ingredient stays good once bought,
 * so the suggestion ranker can favour recipes that use it up.
 *
 * Windows are per ingredient, per week, never per recipe: if two meals both use
 * sour cream that is one window, refreshed rather than duplicated.
 */
class UseByWindowTracker
{
    /**
     * The plan week runs Sunday to Saturday, matching the Sunday planning
     * session and shopping day in spec 1, rather than an ISO Monday week.
     */
    public static function weekStart(Carbon $date): Carbon
    {
        return $date->copy()->startOfWeek(Carbon::SUNDAY)->startOfDay();
    }

    /**
     * Open a window for every perishable ingredient a primary recipe uses.
     *
     * Only primary recipe components count (spec 4.1): a side salad should not
     * start a clock, and simple items have no reliable ingredient breakdown.
     */
    public function trackForComponent(MealComponent $component): Collection
    {
        if (! $component->drivesSuggestionLogic()) {
            return collect();
        }

        $recipe = $component->recipe;

        if (! $recipe || ! $recipe->ingredients_status->hasIngredients()) {
            return collect();
        }

        $date = $component->mealPlanEntry->date;
        $purchaseDate = HouseholdSetting::current()->upcomingShoppingDate($date);

        return $recipe->ingredients
            ->filter(fn (Ingredient $i) => $i->isPerishable())
            ->map(fn (Ingredient $i) => $this->openWindow($i, $date, $purchaseDate))
            ->values();
    }

    /**
     * Create the window, or push its expiry out if one already exists. Taking
     * the later date means a second recipe refreshes the window rather than
     * shortening it (spec 4.1).
     */
    public function openWindow(Ingredient $ingredient, Carbon $planDate, Carbon $purchaseDate): IngredientUseByWindow
    {
        $weekStart = self::weekStart($planDate);
        $expires = $purchaseDate->copy()->addDays($ingredient->shelf_life_days);

        $window = IngredientUseByWindow::firstOrNew([
            'ingredient_id' => $ingredient->id,
            'week_start_date' => $weekStart->toDateString(),
        ]);

        if (! $window->exists || $expires->greaterThan($window->expires_on)) {
            $window->purchase_date = $window->exists
                ? $window->purchase_date->min($purchaseDate)
                : $purchaseDate;
            $window->expires_on = $expires;
            $window->save();
        }

        return $window;
    }

    /**
     * Ingredient ids with a window still open on the given day.
     *
     * Spec 4.2.3 counts anything open from anywhere earlier in the week, not
     * just the immediately preceding day, so the whole week's windows are
     * considered and filtered by expiry.
     *
     * @return Collection<int, string>
     */
    public function openIngredientIds(Carbon $planDate): Collection
    {
        return IngredientUseByWindow::query()
            ->forWeek(self::weekStart($planDate))
            ->open($planDate)
            ->pluck('ingredient_id');
    }

    /**
     * Open windows keyed by ingredient id, for showing "uses up: sour cream".
     *
     * @return Collection<string, IngredientUseByWindow>
     */
    public function openWindows(Carbon $planDate): Collection
    {
        return IngredientUseByWindow::query()
            ->with('ingredient')
            ->forWeek(self::weekStart($planDate))
            ->open($planDate)
            ->get()
            ->keyBy('ingredient_id');
    }
}
