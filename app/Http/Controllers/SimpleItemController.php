<?php

namespace App\Http\Controllers;

use App\Enums\GroceryItemStatus;
use App\Enums\MealTypeHint;
use App\Models\MealComponent;
use App\Models\SimpleItem;
use App\Services\GroceryListBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Spec 4.5 — the one-time breakdown prompt, and the quick-edit view it becomes.
 *
 * "Turkey Sandwich" on the grocery list is useless at a supermarket; turkey,
 * bread, cheese and mayo is a shop. Asked once, then reused forever.
 */
class SimpleItemController extends Controller
{
    public function __construct(
        private readonly GroceryListBuilder $grocery = new GroceryListBuilder,
    ) {}

    public function index(): View
    {
        return view('items.index', [
            'items' => SimpleItem::orderBy('name')->get()->groupBy(
                fn (SimpleItem $item) => $item->meal_type_hint->label(),
            ),
        ]);
    }

    public function edit(Request $request, SimpleItem $simpleItem): View
    {
        return view('items.edit', [
            'item' => $simpleItem,
            // Set when arriving straight from adding it to a slot, which is the
            // only moment the prompt is a prompt rather than an edit screen.
            'justAdded' => $request->boolean('new'),
            'hints' => MealTypeHint::cases(),
        ]);
    }

    public function update(Request $request, SimpleItem $simpleItem): RedirectResponse
    {
        $validated = $request->validate([
            'breakdown' => ['nullable', 'string', 'max:2000'],
            'meal_type_hint' => ['nullable', 'string', 'in:'.implode(',', array_column(MealTypeHint::cases(), 'value'))],
        ]);

        $lines = collect(preg_split('/\r\n|\r|\n|,/', (string) ($validated['breakdown'] ?? '')))
            ->map(fn ($line) => trim((string) $line))
            ->filter(fn ($line) => $line !== '')
            ->unique()
            ->take(30)
            ->values()
            ->all();

        $simpleItem->update([
            'grocery_breakdown' => $lines,
            'meal_type_hint' => $validated['meal_type_hint'] ?? $simpleItem->meal_type_hint,
            // Recorded either way: skipping is an answer, and spec 4.5 says a
            // skipped prompt falls back to the raw name rather than nagging.
            'breakdown_prompted' => true,
        ]);

        $this->refreshPendingGroceryLines($simpleItem);

        return redirect()->route('items')
            ->with('status', $lines === []
                ? "{$simpleItem->name} will be added to the list as one line."
                : "{$simpleItem->name} breaks down into ".count($lines).' items.');
    }

    /**
     * Skipping is explicit, so the prompt does not reappear next time.
     */
    public function skip(SimpleItem $simpleItem): RedirectResponse
    {
        $simpleItem->update(['breakdown_prompted' => true]);

        return redirect()->route('plan')->with('status', "{$simpleItem->name} added.");
    }

    public function destroy(SimpleItem $simpleItem): RedirectResponse
    {
        $name = $simpleItem->name;
        $simpleItem->delete();

        return back()->with('status', "{$name} removed from the library.");
    }

    /**
     * A breakdown saved moments after the item was added to a slot needs to
     * reach the grocery list that slot already generated, or the first use of
     * every compound item silently stays a useless single line.
     *
     * Only untouched lines for today and later are rebuilt: anything already
     * ticked off, or belonging to a past meal, is left alone.
     */
    private function refreshPendingGroceryLines(SimpleItem $simpleItem): void
    {
        $components = MealComponent::query()
            ->where('simple_item_id', $simpleItem->id)
            ->whereHas('mealPlanEntry', fn ($q) => $q->whereDate('date', '>=', Carbon::today()->toDateString()))
            ->with('mealPlanEntry', 'simpleItem')
            ->get();

        foreach ($components as $component) {
            $hasPurchased = $component->groceryListItems()
                ->where('status', GroceryItemStatus::Purchased->value)
                ->exists();

            if ($hasPurchased) {
                continue;
            }

            $this->grocery->removeForComponent($component);
            $this->grocery->addForComponent($component);
        }
    }
}
