<?php

namespace App\Http\Controllers;

use App\Enums\GroceryItemStatus;
use App\Enums\MealSlot;
use App\Models\GroceryListItem;
use App\Models\MealPlanEntry;
use App\Services\InventoryService;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Spec 5, Home Screen: two large, tap-friendly destinations. The counts around
 * them exist so the screen answers "is anything waiting for me?" without a tap.
 */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        $today = Carbon::today();

        $tonight = MealPlanEntry::query()
            ->with('components.recipe', 'components.simpleItem')
            ->whereDate('date', $today->toDateString())
            ->where('slot', MealSlot::Dinner->value)
            ->first();

        $weekStart = $today->copy()->startOfWeek(Carbon::SUNDAY);

        // What is on today for the two smaller buttons, so the home screen
        // still answers "what is it?" without a tap.
        $todaysMeals = MealPlanEntry::query()
            ->with('components.recipe', 'components.simpleItem')
            ->whereDate('date', $today->toDateString())
            ->whereIn('slot', [MealSlot::Breakfast->value, MealSlot::Lunch->value])
            ->get()
            ->mapWithKeys(fn (MealPlanEntry $entry) => [
                $entry->slot->value => $entry->components->map->displayName()->filter()->join(', '),
            ])
            ->filter(fn (string $names) => $names !== '');

        return view('home', [
            'tonight' => $tonight,
            'today' => $today,
            'todaysMeals' => $todaysMeals,
            'plannedDinners' => MealPlanEntry::query()
                ->where('slot', MealSlot::Dinner->value)
                ->whereBetween('date', [
                    $weekStart->toDateString(),
                    $weekStart->copy()->addDays(6)->toDateString(),
                ])
                ->whereHas('components', fn ($q) => $q->where('is_primary', true))
                ->count(),
            'groceryCount' => GroceryListItem::where('status', GroceryItemStatus::Needed->value)->count(),
            // Surfaced here because a pantry nobody opens steers nothing.
            'atRiskCount' => (new InventoryService)->atRiskIngredientIds($today)->count(),
        ]);
    }
}
