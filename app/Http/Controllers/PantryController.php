<?php

namespace App\Http\Controllers;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Services\GroceryListBuilder;
use App\Services\IngredientResolver;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The pantry: a rough picture of what is in, and what is about to go off.
 *
 * Not a stock ledger. It is right often enough to be worth asking before
 * deciding dinner, and wrong often enough that every figure is editable.
 */
class PantryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory = new InventoryService,
        private readonly IngredientResolver $ingredients = new IngredientResolver,
    ) {}

    public function index(): View
    {
        $today = Carbon::today();

        $inStock = InventoryFlag::query()
            ->with('ingredient')
            ->inStock()
            ->get()
            ->filter(fn (InventoryFlag $flag) => $flag->ingredient !== null)
            ->sortBy(fn (InventoryFlag $flag) => mb_strtolower($flag->ingredient->name));

        $atRiskIds = $this->inventory->atRiskIngredientIds($today)->all();

        // The spice rack is listed on its own rather than scattered through
        // the categories. Forty jars would otherwise bury the dozen things
        // that actually change week to week, and none of them need the
        // amount-and-expiry controls the other rows carry.
        $staples = $inStock->filter(fn (InventoryFlag $flag) => $flag->ingredient->isStaple());
        $inStock = $inStock->reject(fn (InventoryFlag $flag) => $flag->ingredient->isStaple());

        return view('pantry.index', [
            'staples' => $staples
                ->sortBy(fn (InventoryFlag $flag) => mb_strtolower($flag->ingredient->name))
                ->values(),
            // Anything going off soon leads, because that is the whole reason
            // to look at this screen before planning a meal.
            'atRisk' => $inStock
                ->filter(fn (InventoryFlag $flag) => in_array($flag->ingredient_id, $atRiskIds, true))
                ->sortBy('expires_on')
                ->values(),
            'byCategory' => $inStock
                ->reject(fn (InventoryFlag $flag) => in_array($flag->ingredient_id, $atRiskIds, true))
                ->groupBy(fn (InventoryFlag $flag) => $flag->ingredient->category->value)
                ->sortKeys(),
            'categories' => collect(IngredientCategory::cases())->keyBy->value,
            'total' => $inStock->count(),
            'today' => $today,
            'recentlyUsed' => InventoryFlag::query()
                ->with('ingredient')
                ->where('has_stock', false)
                ->whereNotNull('last_updated')
                ->latest('last_updated')
                ->limit(12)
                ->get()
                ->filter(fn (InventoryFlag $flag) => $flag->ingredient !== null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'unit' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        // Resolved through the shared resolver so a pantry entry matches the
        // same ingredient a recipe refers to — otherwise it could never affect
        // a suggestion, which is the point of tracking it.
        $ingredient = $this->ingredients->resolve($validated['name']);

        $flag = $this->inventory->add(
            $ingredient,
            $validated['quantity'] ?? null,
            $validated['unit'] ?? null,
            null,
            $validated['note'] ?? null,
        );

        return back()->with('status', "{$ingredient->name} added, good until {$flag->expires_on->format('j M')}.");
    }

    public function update(Request $request, InventoryFlag $flag): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'unit' => ['nullable', 'string', 'max:20'],
            'expires_on' => ['nullable', 'date'],
        ]);

        $quantity = $validated['quantity'] ?? null;

        $flag->update([
            'quantity' => $quantity,
            'unit' => $validated['unit'] ?? $flag->unit,
            'expires_on' => $validated['expires_on'] ?? $flag->expires_on,
            // Setting it to zero by hand is the same statement as "it's gone".
            'has_stock' => $quantity === null || (float) $quantity > 0,
            'last_updated' => now(),
        ]);

        return back()->with('status', 'Pantry updated.');
    }

    public function markGone(InventoryFlag $flag): RedirectResponse
    {
        $name = $flag->ingredient?->name ?? 'Item';
        $this->inventory->markGone($flag);

        return back()->with('status', "{$name} marked as gone.");
    }

    /**
     * Straight onto the grocery list from the pantry.
     *
     * Offered on the use-these-up rows especially: something going off is
     * often something bought every week, and noticing that is the moment to
     * put it on the list rather than a prompt to go and find the list.
     */
    public function addToGrocery(InventoryFlag $flag, GroceryListBuilder $grocery): RedirectResponse
    {
        $ingredient = $flag->ingredient;

        if (! $ingredient) {
            return back()->withErrors(['flag' => 'That ingredient no longer exists.']);
        }

        $item = $grocery->addForIngredient($ingredient);

        return back()->with('status', $item->wasRecentlyCreated
            ? "{$ingredient->name} added to the grocery list."
            : "{$ingredient->name} was already on the grocery list.");
    }

    /**
     * Put something back that was marked gone by mistake, or restock it without
     * going via the grocery list.
     */
    public function restock(InventoryFlag $flag): RedirectResponse
    {
        $ingredient = $flag->ingredient;

        if (! $ingredient) {
            return back()->withErrors(['flag' => 'That ingredient no longer exists.']);
        }

        $this->inventory->add($ingredient, null, $flag->unit);

        return back()->with('status', "{$ingredient->name} is back in the pantry.");
    }
}
