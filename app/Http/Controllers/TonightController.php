<?php

namespace App\Http\Controllers;

use App\Enums\MealSlot;
use App\Models\MealPlanEntry;
use App\Services\HouseholdSizeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Spec 5, What's For Dinner: tonight's meal, with ingredients already scaled to
 * tonight's household size so nobody has to do arithmetic at the stove.
 */
class TonightController extends Controller
{
    public function __invoke(Request $request, HouseholdSizeResolver $servings): View
    {
        $date = $request->query('date')
            ? Carbon::createFromFormat('Y-m-d', $request->query('date'))->startOfDay()
            : Carbon::today();

        // Breakfast and lunch get the same screen, because the reason to open
        // it is the same: see what it is, then say it was made so the pantry
        // knows.
        $slot = MealSlot::tryFrom((string) $request->query('slot')) ?? MealSlot::Dinner;

        $entry = MealPlanEntry::query()
            ->with([
                'components.recipe.ingredients.inventoryFlag',
                'components.simpleItem',
            ])
            ->whereDate('date', $date->toDateString())
            ->where('slot', $slot->value)
            ->first();

        $primary = $entry?->primaryComponent();
        $recipe = $primary?->recipe;

        $scaled = collect();

        if ($recipe && $recipe->ingredients_status->hasIngredients()) {
            $multiplier = $recipe->servingMultiplierFor($primary->servings_needed);

            $scaled = $recipe->ingredients->map(fn ($ingredient) => [
                'name' => $ingredient->name,
                // Stored per serving, so the recipe's own yield is reapplied
                // before the scaling multiplier (spec 4.3).
                'quantity' => $ingredient->pivot->quantity_per_serving === null
                    ? null
                    : round($ingredient->pivot->quantity_per_serving * $recipe->base_servings * $multiplier, 2),
                'unit' => $ingredient->pivot->unit ?? $ingredient->default_unit,
                // Spec 4.6: in-stock items are still shown here, with a note,
                // even though they were kept off the grocery list.
                'fromStock' => $ingredient->hasStock(),
            ]);
        }

        return view('tonight', [
            'date' => $date,
            'slot' => $slot,
            'isToday' => $date->isSameDay(Carbon::today()),
            // Yesterday matters more than tomorrow here: forgetting to mark a
            // dinner made is something you notice the next morning, and until
            // now there was no way back to it.
            'previousDay' => $date->copy()->subDay(),
            'nextDay' => $date->copy()->addDay(),
            'entry' => $entry,
            'primary' => $primary,
            'recipe' => $recipe,
            'extras' => $entry?->components->where('is_primary', false) ?? collect(),
            'scaled' => $scaled,
            'servingsForTonight' => $entry ? $servings->forEntry($entry) : $servings->scheduledFor($date),
            'multiplier' => $recipe && $primary ? $recipe->servingMultiplierFor($primary->servings_needed) : 1,
        ]);
    }
}
