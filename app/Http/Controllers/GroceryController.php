<?php

namespace App\Http\Controllers;

use App\Enums\GroceryAisle;
use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\RepeaterItem;
use App\Services\GroceryListBuilder;
use App\Services\InventoryService;
use App\Support\AisleGuesser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Spec 5, Grocery List.
 */
class GroceryController extends Controller
{
    public function __construct(
        private readonly GroceryListBuilder $grocery = new GroceryListBuilder,
        private readonly AisleGuesser $aisles = new AisleGuesser,
        private readonly InventoryService $inventory = new InventoryService,
    ) {}

    public function index(): View
    {
        // Spec 4.6: repeaters surface on their own schedule, so opening the list
        // is the natural moment to pull in anything that has come due.
        $this->grocery->syncDueRepeaters();

        $items = GroceryListItem::query()
            ->with('sourceComponent.recipe', 'sourceComponent.simpleItem', 'ingredient')
            ->get()
            // Purchased lines stay in place, crossed off, rather than moving to
            // a separate list: the shop is walked aisle by aisle, and an item
            // jumping out of its section mid-shop loses your place.
            ->sortBy([
                fn (GroceryListItem $a, GroceryListItem $b) => ($a->status === GroceryItemStatus::Purchased ? 1 : 0)
                    <=> ($b->status === GroceryItemStatus::Purchased ? 1 : 0),
                fn (GroceryListItem $a, GroceryListItem $b) => strcasecmp($a->item_name, $b->item_name),
            ]);

        $grouped = $items->groupBy(fn (GroceryListItem $item) => ($item->aisle ?? GroceryAisle::Other)->value);

        return view('grocery.index', [
            // Ordered by how a shop is walked; empty sections drop out here
            // rather than being hidden in the view.
            'aisles' => collect(GroceryAisle::inShoppingOrder())
                ->map(fn (GroceryAisle $aisle) => [
                    'aisle' => $aisle,
                    'items' => $grouped->get($aisle->value, collect()),
                ])
                ->filter(fn (array $section) => $section['items']->isNotEmpty())
                ->values(),
            'neededCount' => $items->where('status', GroceryItemStatus::Needed)->count(),
            'purchasedCount' => $items->where('status', GroceryItemStatus::Purchased)->count(),
            'allAisles' => GroceryAisle::inShoppingOrder(),
            'suggestions' => $this->autofillNames(),
        ]);
    }

    /**
     * Everything ever put on the list, plus every known ingredient, so typing
     * three letters finds what was bought last week without retyping it.
     *
     * @return list<string>
     */
    private function autofillNames(): array
    {
        return GroceryListItem::query()
            // Includes cleared lines, which is the point: last week's shopping
            // is exactly what you want to retype least.
            ->withTrashed()
            ->distinct()
            ->orderBy('item_name')
            ->pluck('item_name')
            ->merge(Ingredient::orderBy('name')->pluck('name'))
            ->merge(RepeaterItem::orderBy('item_name')->pluck('item_name'))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->sort(fn ($a, $b) => strcasecmp($a, $b))
            ->values()
            ->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'aisle' => ['nullable', 'string', 'in:'.implode(',', array_column(GroceryAisle::cases(), 'value'))],
        ]);

        $this->grocery->addManual(
            trim($validated['item_name']),
            $validated['quantity'] ?? null,
            $validated['unit'] ?? null,
            null,
            filled($validated['aisle'] ?? null) ? GroceryAisle::from($validated['aisle']) : null,
        );

        return back()->with('status', 'Added to the list.');
    }

    /**
     * Correcting an item's aisle also teaches the guesser, since it looks at
     * what the same name was last filed under.
     */
    public function setAisle(Request $request, GroceryListItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'aisle' => ['required', 'string', 'in:'.implode(',', array_column(GroceryAisle::cases(), 'value'))],
        ]);

        $item->update(['aisle' => GroceryAisle::from($validated['aisle'])]);

        return back()->with('status', "{$item->item_name} moved to {$item->aisle->label()}.");
    }

    public function toggle(GroceryListItem $item): RedirectResponse
    {
        $nowPurchased = $item->status === GroceryItemStatus::Needed;

        $item->update([
            'status' => $nowPurchased ? GroceryItemStatus::Purchased : GroceryItemStatus::Needed,
        ]);

        // Spec 4.6: buying a repeater is what restarts its clock.
        if ($nowPurchased && $item->source === GroceryItemSource::Repeater) {
            RepeaterItem::where('item_name', $item->item_name)->first()?->markPurchased(Carbon::today());
        }

        // Ticking something off is the app's one reliable observation that it
        // came into the house, so it is what stocks the pantry.
        if ($nowPurchased) {
            $this->inventory->recordPurchase($item);
        }

        return back();
    }

    public function destroy(GroceryListItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('status', 'Removed.');
    }

    /**
     * Spec 5: toggle an ingredient's "have stock" flag straight from the list.
     * Flagging it also takes it off this list, which is the point of the flag.
     */
    public function markStocked(GroceryListItem $item): RedirectResponse
    {
        if (! $item->ingredient_id) {
            return back()->withErrors(['item' => 'Only recipe ingredients can be marked as in stock.']);
        }

        InventoryFlag::updateOrCreate(
            ['ingredient_id' => $item->ingredient_id],
            ['has_stock' => true, 'last_updated' => now()],
        );

        $name = $item->item_name;
        $item->delete();

        return back()->with('status', "{$name} marked as already in stock.");
    }

    public function clearPurchased(): RedirectResponse
    {
        $count = GroceryListItem::where('status', GroceryItemStatus::Purchased->value)->delete();

        return back()->with('status', "Cleared {$count} purchased ".str('item')->plural($count).'.');
    }
}
