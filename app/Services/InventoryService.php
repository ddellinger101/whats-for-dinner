<?php

namespace App\Services;

use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\Recipe;
use App\Models\SimpleItem;
use App\Support\IngredientLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a rough picture of what is in the fridge and pantry.
 *
 * Fed from the two moments the app can actually observe: ticking something off
 * the grocery list means it came into the house, and marking a recipe as made
 * means some of it left again. Everything else is the household correcting it
 * by hand, which is expected rather than a failure — this is an aid to
 * deciding dinner, not a stock ledger.
 */
class InventoryService
{
    /** How far ahead counts as "use this up" for the suggestion ranker. */
    public const AT_RISK_DAYS = 4;

    public function __construct(
        private readonly IngredientResolver $ingredients = new IngredientResolver,
    ) {}

    /**
     * A bought grocery line entered the house.
     *
     * Lines added by hand carry no ingredient_id, and they are the majority of
     * a real list — so resolving the name is not a nicety, it is the difference
     * between the pantry filling up and staying permanently empty. Resolution
     * goes through the shared resolver, so "half & half" typed into the grocery
     * list is the same row a recipe refers to and can actually steer a
     * suggestion.
     */
    public function recordPurchase(GroceryListItem $item, ?Carbon $on = null): ?InventoryFlag
    {
        $ingredient = $item->ingredient_id
            ? ($item->ingredient ?? Ingredient::find($item->ingredient_id))
            : $this->ingredients->resolve($item->item_name);

        if (! $ingredient) {
            return null;
        }

        // Link it back, so the line can be marked "already in stock" later and
        // a re-buy updates the same pantry row.
        if (! $item->ingredient_id) {
            $item->update(['ingredient_id' => $ingredient->id]);
        }

        $on ??= Carbon::today();
        $existing = InventoryFlag::where('ingredient_id', $ingredient->id)->first();

        // Buying more of something already in stock adds to it rather than
        // replacing it, and pushes the expiry out to the newer purchase.
        $quantity = match (true) {
            $item->quantity === null => $existing?->quantity,
            $existing?->quantity === null => (float) $item->quantity,
            default => round((float) $existing->quantity + (float) $item->quantity, 3),
        };

        return InventoryFlag::updateOrCreate(
            ['ingredient_id' => $ingredient->id],
            [
                'has_stock' => true,
                'quantity' => $quantity,
                'unit' => $item->unit ?? $existing?->unit ?? $ingredient->default_unit,
                'acquired_on' => $on,
                // Nothing for the spice rack, and nothing for the things this
                // house always eats before they turn. Re-buying must not put a
                // date back on what was deliberately cleared.
                'expires_on' => $ingredient->tracksExpiry()
                    ? $on->copy()->addDays($ingredient->shelf_life_days)
                    : null,
                'last_updated' => now(),
            ],
        );
    }

    /**
     * Correct what is on hand by a difference, for when the amount bought turns
     * out not to be the amount recorded.
     *
     * An unquantified pantry entry is left unquantified: adding a number to
     * "some, amount unknown" would invent a precision nobody has.
     */
    public function adjustBy(?Ingredient $ingredient, float $delta, ?string $unit = null): ?InventoryFlag
    {
        if (! $ingredient || $delta === 0.0) {
            return null;
        }

        $flag = InventoryFlag::where('ingredient_id', $ingredient->id)->first();

        if (! $flag || $flag->quantity === null) {
            return $flag;
        }

        $remaining = round((float) $flag->quantity + $delta, 3);

        $flag->update([
            'quantity' => max(0, $remaining),
            'has_stock' => $remaining > 0,
            'unit' => $flag->unit ?? $unit,
            'last_updated' => now(),
        ]);

        return $flag;
    }

    /**
     * A meal was cooked, so its ingredients came out of the pantry.
     *
     * Scaled the same way the grocery list was (spec 4.3), so what is deducted
     * matches what was bought for it.
     */
    public function consumeForRecipe(Recipe $recipe, int $servingsNeeded): int
    {
        if (! $recipe->ingredients_status->hasIngredients()) {
            return 0;
        }

        $multiplier = $recipe->servingMultiplierFor($servingsNeeded);
        $touched = 0;

        DB::transaction(function () use ($recipe, $multiplier, &$touched) {
            $recipe->loadMissing('ingredients');

            foreach ($recipe->ingredients as $ingredient) {
                // Cooking does not empty the spice rack in any sense the app
                // should track. Draining a jar a teaspoon at a time would
                // eventually mark it gone and put it back on the list, which
                // is the whole thing staples exist to prevent.
                if ($ingredient->isStaple()) {
                    continue;
                }

                $flag = InventoryFlag::where('ingredient_id', $ingredient->id)->inStock()->first();

                if (! $flag) {
                    continue;
                }

                $perServing = $ingredient->pivot->quantity_per_serving;

                $flag->consume($perServing === null
                    ? null
                    : $perServing * $recipe->base_servings * $multiplier);

                $touched++;
            }
        });

        return $touched;
    }

    /**
     * A simple item was eaten, so take its parts out of the pantry.
     *
     * Only what is already there is touched. A breakdown line is free text
     * someone typed once — "1 banana" — and creating an ingredient row just to
     * consume it would fill the archive with things nobody has.
     *
     * The amount is not guessed at. A simple item never carried one, and two
     * omelettes are not two eggs; recording use without a number is the honest
     * answer and leaves the figure for a human to correct.
     */
    public function consumeForSimpleItem(SimpleItem $item): int
    {
        $touched = 0;

        foreach ($item->groceryLines() as $line) {
            $name = IngredientLine::parse($line)->name;

            if ($name === '') {
                continue;
            }

            $ingredient = $this->ingredients->find($name);

            if (! $ingredient) {
                continue;
            }

            $flag = InventoryFlag::where('ingredient_id', $ingredient->id)->inStock()->first();

            if (! $flag || $ingredient->isStaple()) {
                continue;
            }

            $flag->consume(null);
            $touched++;
        }

        return $touched;
    }

    /**
     * Ingredient ids on hand and about to go off.
     *
     * This is the half of the use-up signal that comes from the fridge rather
     * than from the week's plan (spec 4.1 covers the other half).
     *
     * @return Collection<int, string>
     */
    public function atRiskIngredientIds(?Carbon $asOf = null, int $withinDays = self::AT_RISK_DAYS): Collection
    {
        return InventoryFlag::query()
            ->expiringWithin($withinDays, $asOf)
            ->pluck('ingredient_id');
    }

    /**
     * @return Collection<int, InventoryFlag>
     */
    public function atRisk(?Carbon $asOf = null, int $withinDays = self::AT_RISK_DAYS): Collection
    {
        return InventoryFlag::query()
            ->with('ingredient')
            ->expiringWithin($withinDays, $asOf)
            ->orderBy('expires_on')
            ->get();
    }

    /**
     * Put something in by hand, for the half of a pantry the app never sees.
     */
    public function add(Ingredient $ingredient, ?float $quantity, ?string $unit, ?Carbon $acquiredOn = null, ?string $note = null): InventoryFlag
    {
        $acquiredOn ??= Carbon::today();

        return InventoryFlag::updateOrCreate(
            ['ingredient_id' => $ingredient->id],
            [
                'has_stock' => true,
                'quantity' => $quantity,
                'unit' => $unit ?? $ingredient->default_unit,
                'acquired_on' => $acquiredOn,
                // The user's words: these won't expire. A date here would put
                // the spice rack in the "use these up" list — and the same
                // goes for anything marked as never going off.
                'expires_on' => $ingredient->tracksExpiry()
                    ? $acquiredOn->copy()->addDays($ingredient->shelf_life_days)
                    : null,
                'note' => $note,
                'last_updated' => now(),
            ],
        );
    }

    /**
     * Used up or thrown out. The row is kept rather than deleted so the
     * ingredient's own history — and its shelf life — stay put.
     */
    public function markGone(InventoryFlag $flag): InventoryFlag
    {
        $flag->update([
            'has_stock' => false,
            'quantity' => 0,
            'last_updated' => now(),
        ]);

        return $flag;
    }
}
