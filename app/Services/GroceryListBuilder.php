<?php

namespace App\Services;

use App\Enums\GroceryAisle;
use App\Enums\GroceryItemSource;
use App\Enums\GroceryItemStatus;
use App\Models\GroceryLineSource;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\MealComponent;
use App\Models\RepeaterItem;
use App\Support\AisleGuesser;
use App\Support\UnitConversion;
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
     * Ingredients scaled per spec 4.3, minus anything already in the fridge.
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
            // still shown in the recipe view with a "from fridge" note, which is
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

                $unit = $ingredient->pivot->unit ?? $ingredient->default_unit;

                $line = $this->lineFor(
                    name: $ingredient->name,
                    unit: $unit,
                    source: GroceryItemSource::AutoRecipe,
                    addedOn: $addedOn,
                    ingredient: $ingredient,
                );

                return $this->contribute($line, $component, $needed, $unit);
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
            ->map(function (string $name) use ($component, $addedOn) {
                $line = $this->lineFor(
                    name: $name,
                    unit: null,
                    source: GroceryItemSource::AutoSimpleItem,
                    addedOn: $addedOn,
                );

                return $this->contribute($line, $component, (float) $component->servings_needed, null);
            })
            ->values();
    }

    /**
     * The line this thing belongs on, made if it is not there yet.
     *
     * One line per thing, however many meals want it: olive oil in three
     * recipes was three lines on the list and left the adding up to whoever
     * was holding the phone in the shop.
     *
     * Units have to be addable, not identical: six tablespoons and half a cup
     * are fourteen tablespoons of the same oil. A clove and a can are units of
     * different things, so those only join a line already counted the same way.
     */
    private function lineFor(
        string $name,
        ?string $unit,
        GroceryItemSource $source,
        Carbon $addedOn,
        ?Ingredient $ingredient = null,
    ): GroceryListItem {
        $existing = GroceryListItem::query()
            ->needed()
            ->when(
                $ingredient !== null,
                fn ($q) => $q->where('ingredient_id', $ingredient->id),
                fn ($q) => $q->whereNull('ingredient_id')
                    ->whereRaw('LOWER(item_name) = ?', [mb_strtolower($name)]),
            )
            ->get()
            ->first(fn (GroceryListItem $line) => UnitConversion::compatible($line->unit, $unit));

        if ($existing) {
            return $existing;
        }

        return GroceryListItem::create([
            'item_name' => $ingredient->name ?? $name,
            'unit' => $unit,
            'aisle' => $this->aisles->guess($ingredient->name ?? $name, $ingredient),
            'source' => $source,
            'status' => GroceryItemStatus::Needed,
            'added_date' => $addedOn,
            'ingredient_id' => $ingredient?->id,
        ]);
    }

    /**
     * Record what this meal wants and restate the line's total.
     */
    private function contribute(
        GroceryListItem $line,
        MealComponent $component,
        ?float $quantity,
        ?string $unit,
    ): GroceryListItem {
        GroceryLineSource::updateOrCreate(
            ['grocery_list_item_id' => $line->id, 'meal_component_id' => $component->id],
            ['quantity' => $quantity, 'unit' => $unit],
        );

        return $this->recompute($line);
    }

    /**
     * Add the contributions back up.
     *
     * An amount someone typed in themselves is left alone. The whole point of
     * editing it is that the shop sells a pack of eight when the week needs
     * two, and having the plan overwrite that on its next change would undo
     * the decision.
     */
    private function recompute(GroceryListItem $line): GroceryListItem
    {
        $line->load('sources');

        // Converted where they need to be, so tablespoons and cups of the same
        // oil become one figure. An unknown amount leaves the total unknown,
        // because pretending otherwise would understate the line.
        [$planned, $unit] = UnitConversion::sum(
            $line->sources
                ->map(fn (GroceryLineSource $s) => [
                    $s->quantity === null ? null : (float) $s->quantity,
                    $s->unit,
                ])
                ->all(),
        );

        $wasUntouched = $line->quantity === null
            || $line->planned_quantity === null
            || (float) $line->quantity === (float) $line->planned_quantity;

        $line->update([
            'planned_quantity' => $planned,
            'quantity' => $wasUntouched ? $planned : $line->quantity,
            'unit' => $unit ?? $line->unit,
        ]);

        return $line->fresh();
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
        $sources = GroceryLineSource::with('groceryListItem')
            ->where('meal_component_id', $component->id)
            ->get();

        $removed = 0;

        foreach ($sources as $source) {
            $line = $source->groceryListItem;
            $source->delete();

            if (! $line) {
                continue;
            }

            // Once someone has ticked an item off, the line records a shopping
            // decision rather than a plan, so it stays as it is. Manual lines
            // are never touched at all.
            $isAuto = in_array($line->source, [
                GroceryItemSource::AutoRecipe,
                GroceryItemSource::AutoSimpleItem,
            ], true);

            if (! $isAuto || $line->status !== GroceryItemStatus::Needed) {
                continue;
            }

            // Other meals still want it, so the line stays and only this
            // meal's share comes back off the total.
            if ($line->sources()->exists()) {
                $this->recompute($line);

                continue;
            }

            $line->delete();
            $removed++;
        }

        return $removed;
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
     * Put something on the list straight from the pantry.
     *
     * Matched on the ingredient rather than on a unit, because this is not a
     * meal asking for an amount — it is someone saying they want more of a
     * thing. Anything already on the list is returned as it stands, so tapping
     * twice does not make two lines; the caller can tell which happened from
     * wasRecentlyCreated.
     */
    public function addForIngredient(Ingredient $ingredient, ?Carbon $addedOn = null): GroceryListItem
    {
        $existing = GroceryListItem::query()
            ->needed()
            ->where('ingredient_id', $ingredient->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return GroceryListItem::create([
            'item_name' => $ingredient->name,
            'aisle' => $this->aisles->guess($ingredient->name, $ingredient),
            'source' => GroceryItemSource::Manual,
            'status' => GroceryItemStatus::Needed,
            'added_date' => $addedOn ?? Carbon::today(),
            'ingredient_id' => $ingredient->id,
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
