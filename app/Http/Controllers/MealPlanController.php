<?php

namespace App\Http\Controllers;

use App\Enums\CategoryTag;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Models\HouseholdSetting;
use App\Models\MealComponent;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\SimpleItem;
use App\Services\HouseholdSizeResolver;
use App\Services\InventoryService;
use App\Services\MealPlanner;
use App\Services\RecipeSuggestionRanker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            // Ingredients come along because tapping a planned meal opens its
            // recipe in place. A week is a dozen or so recipes, which is far
            // cheaper than a round trip per tap — and the images inside the
            // closed dialogs are lazy, so none of them load until one is opened.
            ->with(['components.recipe.ingredients', 'components.simpleItem'])
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

        $search = trim((string) $request->query('q', ''));
        $protein = $request->query('protein');
        $tag = $request->query('tag');

        // Filtering happens after ranking rather than inside it, so a narrowed
        // list keeps the spec 4.2 order — the use-up boost still floats to the
        // top of whatever subset is showing.
        $suggestions = $wantsPrimary
            ? (new RecipeSuggestionRanker)->for($day, limit: 500)
                ->when($search !== '', fn ($all) => $all->filter(
                    fn ($s) => str_contains(mb_strtolower($s->recipe->name), mb_strtolower($search)),
                ))
                ->when($protein, fn ($all) => $all->filter(
                    fn ($s) => $s->recipe->protein_type->value === $protein,
                ))
                ->when($tag, fn ($all) => $all->filter(
                    fn ($s) => $s->recipe->category_tags->contains(fn ($t) => $t->value === $tag),
                ))
                ->take(40)
                ->values()
            : collect();

        return view('plan.picker', [
            'day' => $day,
            'slot' => $mealSlot,
            'entry' => $entry,
            'wantsPrimary' => $wantsPrimary,
            'suggestions' => $suggestions,
            'search' => $search,
            'protein' => $protein,
            'tag' => $tag,
            'proteins' => ProteinType::cases(),
            'cuisines' => CategoryTag::cuisines(),
            'styles' => CategoryTag::styles(),
            'simpleItems' => SimpleItem::query()
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->orderBy('name')
                ->limit(40)
                ->get(),
            'recipes' => $this->pickerRecipes($mealSlot, $search),
            'dietMode' => HouseholdSetting::current()->diet_mode,
        ]);
    }

    /**
     * The recipes worth offering under a breakfast or lunch slot.
     *
     * Those slots default to the simple-item library (spec 4.5) and only
     * showed a recipe if you already knew its name and typed it. That left the
     * Breakfast and Lunch tags doing nothing where they matter most: the point
     * of tagging banana cookies as breakfast is that they turn up when you are
     * deciding breakfast.
     *
     * Filtered in PHP rather than in SQL because the tags are a JSON column,
     * and a hundred and fifty recipes is nothing to walk.
     *
     * @return Collection<int, Recipe>
     */
    private function pickerRecipes(MealSlot $slot, string $search): Collection
    {
        if ($search !== '') {
            return Recipe::where('name', 'like', "%{$search}%")->orderBy('name')->limit(20)->get();
        }

        $tag = match ($slot) {
            MealSlot::Breakfast => CategoryTag::Breakfast,
            MealSlot::Lunch => CategoryTag::Lunch,
            default => null,
        };

        if (! $tag) {
            return collect();
        }

        return Recipe::selectable()
            ->orderBy('name')
            ->get()
            ->filter(fn (Recipe $recipe) => $recipe->category_tags->contains(
                fn (CategoryTag $t) => $t === $tag,
            ))
            ->take(20)
            ->values();
    }

    /**
     * A planned meal was eaten, so take it out of the pantry.
     *
     * Per component rather than per recipe, because that is what is on the
     * plate: a lunch is three sandwiches, each its own component, and only the
     * primary one had a way to be marked. Everything else on the table was a
     * label you could not act on, which is why breakfast and lunch could never
     * draw the pantry down.
     */
    public function markMade(Request $request, MealComponent $component): RedirectResponse
    {
        $validated = $request->validate([
            'cooked_on' => ['nullable', 'date', 'before_or_equal:today', 'after:-1 year'],
        ]);

        if ($component->wasMade()) {
            return back()->with('status', $component->displayName().' was already marked made.');
        }

        $cookedOn = isset($validated['cooked_on'])
            ? Carbon::parse($validated['cooked_on'])->startOfDay()
            : Carbon::today();

        $inventory = new InventoryService;

        if ($recipe = $component->recipe) {
            $recipe->update([
                'times_made' => $recipe->times_made + 1,
                // Only forward, so marking an older meal does not undo a more
                // recent cook of the same recipe.
                'last_cooked_on' => $recipe->last_cooked_on && $recipe->last_cooked_on->gt($cookedOn)
                    ? $recipe->last_cooked_on
                    : $cookedOn,
            ]);

            $touched = $inventory->consumeForRecipe($recipe, $component->servings_needed);
        } else {
            $touched = $component->simpleItem
                ? $inventory->consumeForSimpleItem($component->simpleItem)
                : 0;
        }

        // Recorded whether or not anything came out of the pantry, since the
        // question it answers is "did I already tick this?".
        $component->update(['made_at' => now()]);

        return back()->with('status', $touched > 0
            ? "Marked {$component->displayName()} as made, and took its ingredients out of the pantry."
            : "Marked {$component->displayName()} as made.");
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
