<?php

namespace App\Http\Controllers;

use App\Enums\MealSlot;
use App\Models\HouseholdSetting;
use App\Models\MealComponent;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\SimpleItem;
use App\Services\HouseholdSizeResolver;
use App\Services\MealPlanner;
use App\Services\RecipeSuggestionRanker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Spec 5, Meal Plan: the week grid and everything that fills it.
 */
class MealPlanController extends Controller
{
    public function __construct(
        private readonly MealPlanner $planner = new MealPlanner,
        private readonly HouseholdSizeResolver $servings = new HouseholdSizeResolver,
    ) {}

    public function index(Request $request): View
    {
        $weekStart = $this->weekStart($request);

        $days = collect(range(0, 6))->map(fn (int $offset) => $weekStart->copy()->addDays($offset));

        $entries = MealPlanEntry::query()
            ->with(['components.recipe', 'components.simpleItem'])
            ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
            ->get()
            // Keyed by "Y-m-d|slot" so the grid can look a slot up directly.
            ->keyBy(fn (MealPlanEntry $e) => $e->date->toDateString().'|'.$e->slot->value);

        return view('plan.index', [
            'weekStart' => $weekStart,
            'days' => $days,
            'entries' => $entries,
            'slots' => MealSlot::ordered(),
            'today' => Carbon::today(),
            'settings' => HouseholdSetting::current(),
            'scheduled' => $days->mapWithKeys(fn (Carbon $d) => [
                $d->toDateString() => $this->servings->scheduledFor($d),
            ]),
        ]);
    }

    /**
     * The add-component screen. A dinner with no main yet gets the ranked
     * suggestion list (spec 4.2); everything else gets the lighter
     * search-or-create picker over the simple-item library (spec 4.5).
     */
    public function picker(Request $request, string $date, string $slot): View
    {
        $day = $this->parseDate($date);
        $mealSlot = MealSlot::from($slot);
        $entry = MealPlanEntry::query()
            ->with('components')
            ->whereDate('date', $day->toDateString())
            ->where('slot', $mealSlot->value)
            ->first();

        $wantsPrimary = $request->boolean('primary', $mealSlot->usesSuggestionEngine() && ! $entry?->primaryComponent());

        $suggestions = $wantsPrimary
            ? (new RecipeSuggestionRanker)->for($day, limit: 30)
            : collect();

        $search = trim((string) $request->query('q', ''));

        return view('plan.picker', [
            'day' => $day,
            'slot' => $mealSlot,
            'entry' => $entry,
            'wantsPrimary' => $wantsPrimary,
            'suggestions' => $suggestions,
            'search' => $search,
            'simpleItems' => SimpleItem::query()
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->limit(40)
                ->get(),
            'recipes' => $search === ''
                ? collect()
                : Recipe::where('name', 'like', "%{$search}%")->orderBy('name')->limit(20)->get(),
            'dietMode' => HouseholdSetting::current()->diet_mode,
        ]);
    }

    public function setPrimary(Request $request, string $date, string $slot): RedirectResponse
    {
        $validated = $request->validate(['recipe_id' => ['required', 'uuid', 'exists:recipes,id']]);

        $recipe = Recipe::findOrFail($validated['recipe_id']);

        if (! $recipe->isSelectable()) {
            // Spec 4.2.1: thumbs-down is blocked from fresh selection until
            // manually un-greyed, so this is enforced server-side too.
            return back()->withErrors(['recipe_id' => 'That recipe is marked thumbs-down. Un-grey it first.']);
        }

        $this->planner->setPrimaryRecipe($this->parseDate($date), MealSlot::from($slot), $recipe);

        return redirect()->route('plan', ['start' => $this->parseDate($date)->startOfWeek(Carbon::SUNDAY)->toDateString()])
            ->with('status', "{$recipe->name} added.");
    }

    public function addSide(Request $request, string $date, string $slot): RedirectResponse
    {
        $validated = $request->validate([
            'simple_item_id' => ['nullable', 'uuid', 'exists:simple_items,id'],
            'recipe_id' => ['nullable', 'uuid', 'exists:recipes,id'],
            'new_item_name' => ['nullable', 'string', 'max:120'],
        ]);

        $thing = match (true) {
            filled($validated['simple_item_id'] ?? null) => SimpleItem::findOrFail($validated['simple_item_id']),
            filled($validated['recipe_id'] ?? null) => Recipe::findOrFail($validated['recipe_id']),
            // Spec 4.5: a name typed on the fly joins the reusable library, so
            // the same lunch item never has to be defined twice.
            filled($validated['new_item_name'] ?? null) => SimpleItem::firstOrCreate(
                ['name' => trim($validated['new_item_name'])],
            ),
            default => null,
        };

        if (! $thing) {
            return back()->withErrors(['new_item_name' => 'Pick something or type a name.']);
        }

        $this->planner->addSide($this->parseDate($date), MealSlot::from($slot), $thing);

        // Spec 4.5: the first time a simple item is created, ask what it breaks
        // down into. "Turkey Sandwich" on a grocery list is useless in a shop;
        // turkey, bread, cheese and mayo is a shop. Asked once, reused forever.
        if ($thing instanceof SimpleItem && ! $thing->breakdown_prompted) {
            return redirect()->route('items.edit', ['simpleItem' => $thing, 'new' => 1]);
        }

        return redirect()->route('plan', ['start' => $this->parseDate($date)->startOfWeek(Carbon::SUNDAY)->toDateString()])
            ->with('status', "{$thing->name} added.");
    }

    public function setServings(Request $request, string $date, string $slot): RedirectResponse
    {
        $validated = $request->validate([
            'servings' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $entry = $this->planner->slotFor($this->parseDate($date), MealSlot::from($slot));
        $this->planner->setServings($entry, $validated['servings']);

        return back()->with('status', "Set to {$validated['servings']} servings.");
    }

    public function removeComponent(MealComponent $component): RedirectResponse
    {
        $name = $component->displayName();
        $weekStart = $component->mealPlanEntry->date->copy()->startOfWeek(Carbon::SUNDAY);

        $this->planner->removeComponent($component);

        return redirect()->route('plan', ['start' => $weekStart->toDateString()])
            ->with('status', "{$name} removed.");
    }

    private function weekStart(Request $request): Carbon
    {
        $start = $request->query('start');

        $base = $start ? $this->parseDate($start) : Carbon::today();

        return $base->startOfWeek(Carbon::SUNDAY);
    }

    private function parseDate(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
    }
}
