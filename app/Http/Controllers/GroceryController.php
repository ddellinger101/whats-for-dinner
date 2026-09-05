<?php

namespace App\Http\Controllers;

use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use App\Models\GroceryListItem;
use App\Models\InventoryFlag;
use App\Services\GroceryListBuilder;
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
    ) {}

    public function index(): View
    {
        // Spec 4.6: repeaters surface on their own schedule, so opening the list
        // is the natural moment to pull in anything that has come due.
        $this->grocery->syncDueRepeaters();

        $items = GroceryListItem::query()
            ->with('sourceComponent.recipe', 'sourceComponent.simpleItem', 'ingredient')
            ->orderBy('status')
            ->orderBy('item_name')
            ->get();

        return view('grocery.index', [
            'needed' => $items->where('status', GroceryItemStatus::Needed),
            'purchased' => $items->where('status', GroceryItemStatus::Purchased),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
        ]);

        $this->grocery->addManual(
            trim($validated['item_name']),
            $validated['quantity'] ?? null,
            $validated['unit'] ?? null,
        );

        return back()->with('status', 'Added to the list.');
    }

    public function toggle(GroceryListItem $item): RedirectResponse
    {
        $nowPurchased = $item->status === GroceryItemStatus::Needed;

        $item->update([
            'status' => $nowPurchased ? GroceryItemStatus::Purchased : GroceryItemStatus::Needed,
        ]);

        // Spec 4.6: buying a repeater is what restarts its clock.
        if ($nowPurchased && $item->source === GroceryItemSource::Repeater) {
            \App\Models\RepeaterItem::where('item_name', $item->item_name)
                ->first()?->markPurchased(Carbon::today());
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
