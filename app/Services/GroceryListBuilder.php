<?php

namespace App\Services;

use App\Enums\GroceryAisle;
use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use App\Models\GroceryListItem;
use App\Models\MealComponent;
use App\Models\RepeaterItem;
use App\Support\AisleGuesser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Spec 4.6 — the grocery list grows the moment a component is assigned to a
 * slot, rather than being batched at the end of planning.
 */
class GroceryListBuilder
{
    public function __construct(
        private readonly AisleGuesser $aisles = new AisleGuesser,
    ) {}

    /**
     * Add everything a newly assigned component needs.
     */
    public function addForComponent(MealComponent $component, ?Carbon $addedOn = null): Collection
    {
        $addedOn ??= Carbon::today();

        return $component->recipe
            ? $this->addRecipeIngredients($component, $addedOn)
            : $this->addSimpleItemLines($component, $addedOn);
    }

    /**
     * Ingredients scaled per spec 4.3, minus anything already in the freezer.
     */
    private function addRecipeIngredients(MealComponent $component, Carbon $addedOn): Collection
    {
        $recipe = $component->recipe;

        if (! $recipe->ingredients_status->hasIngredients()) {
            return collect();
        }

        $multiplier = $recipe->servingMultiplierFor($component->servings_needed);

        return $recipe->ingredients
            // Spec 4.6: an ingredient flagged as in stock is skipped here. It is
            // still shown in the recipe view with a "from freezer" note, which is
            // a display concern, not a list one.
            //
            // Spice-rack staples are skipped for the same reason but on
            // standing grounds rather than this week's observation: a recipe
            // wanting half a teaspoon of paprika is not a reason to buy
            // paprika. Anything a recipe asks for fresh never reaches here —
            // it resolves to its own produce row, which is not a staple.
            ->reject(fn ($ingredient) => $ingredient->hasStock() || $ingredient->isStaple())
            ->map(function ($ingredient) use ($component, $multiplier, $recipe, $addedOn) {
                $perServing = $ingredient->pivot->quantity_per_serving;

                // Quantities are stored per serving, so the recipe's own yield
                // has to be reapplied before the scaling multiplier.
                $needed = $perServing === null
                    ? null
                    : $perServing * $recipe->base_servings * $multiplier;

                return GroceryListItem::create([
                    'item_name' => $ingredient->name,
                    'quantity' => $needed,
                    // Kept so an edited line still shows what the week asked
                    // for, rather than looking like the plan wanted a whole
                    // pack.
                    'planned_quantity' => $needed,
                    'unit' => $ingredient->pivot->unit ?? $ingredient->default_unit,
                    'aisle' => $this->aisles->guess($ingredient->name, $ingredient),
                    'source' => GroceryItemSource::AutoRecipe,
                    'status' => GroceryItemStatus::Needed,
                    'added_date' => $addedOn,
                    'source_component_id' => $component->id,
                    'ingredient_id' => $ingredient->id,
                ]);
            })
            ->values();
    }

    /**
     * Spec 4.5: a compound item expands into its saved breakdown, a
     * single-concept item contributes its own name. Either way the quantity is
     * a plain head count, not a recipe-style multiplier (spec 4.3).
     */
    private function addSimpleItemLines(MealComponent $component, Carbon $addedOn): Collection
    {
        $item = $component->simpleItem;

        if (! $item) {
            return collect();
        }

        return collect($item->groceryLines())
            ->map(fn (string $line) => GroceryListItem::create([
                'item_name' => $line,
                'quantity' => $component->servings_needed,
                'planned_quantity' => $component->servings_needed,
                'unit' => null,
                'aisle' => $this->aisles->guess($line),
                'source' => GroceryItemSource::AutoSimpleItem,
                'status' => GroceryItemStatus::Needed,
                'added_date' => $addedOn,
                'source_component_id' => $component->id,
            ]))
            ->values();
    }

    /**
     * Retract what a component added when it leaves the plan.
     *
     * Only untouched auto-generated lines are removed: once someone has ticked
     * an item off, deleting it would erase a shopping decision. Manual lines are
     * never touched at all.
     */
    public function removeForComponent(MealComponent $component): int
    {
        return GroceryListItem::query()
            ->where('source_component_id', $component->id)
            ->whereIn('source', [
                GroceryItemSource::AutoRecipe->value,
                GroceryItemSource::AutoSimpleItem->value,
            ])
            ->where('status', GroceryItemStatus::Needed->value)
            ->delete();
    }

    /**
     * Add an item by hand, independent of the meal plan (spec 4.6).
     */
    public function addManual(
        string $name,
        ?float $quantity = null,
        ?string $unit = null,
        ?Carbon $addedOn = null,
        ?GroceryAisle $aisle = null,
    ): GroceryListItem {
        return GroceryListItem::create([
            'item_name' => $name,
            'quantity' => $quantity,
            'unit' => $unit,
            // A chosen aisle wins; otherwise fall back to what the app knows.
            'aisle' => $aisle ?? $this->aisles->guess($name),
            'source' => GroceryItemSource::Manual,
            'status' => GroceryItemStatus::Needed,
            'added_date' => $addedOn ?? Carbon::today(),
        ]);
    }

    /**
     * Surface repeaters that have come due, independent of the meal plan.
     *
     * Skips any repeater already sitting unpurchased on the list, so a weekly
     * shop does not accumulate three lines of paper towels.
     */
    public function syncDueRepeaters(?Carbon $asOf = null): Collection
    {
        $asOf ??= Carbon::today();

        $alreadyListed = GroceryListItem::query()
            ->where('source', GroceryItemSource::Repeater->value)
            ->where('status', GroceryItemStatus::Needed->value)
            ->pluck('item_name')
            ->map(fn ($n) => mb_strtolower($n))
            ->all();

        return RepeaterItem::due($asOf)
            ->get()
            ->reject(fn (RepeaterItem $r) => in_array(mb_strtolower($r->item_name), $alreadyListed, true))
            ->map(fn (RepeaterItem $r) => GroceryListItem::create([
                'item_name' => $r->item_name,
                'aisle' => $this->aisles->guess($r->item_name),
                'source' => GroceryItemSource::Repeater,
                'status' => GroceryItemStatus::Needed,
                'added_date' => $asOf,
            ]))
            ->values();
    }
}
