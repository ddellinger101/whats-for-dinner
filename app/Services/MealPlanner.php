<?php

namespace App\Services;

use App\Enums\ComponentType;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Jobs\ImportRecipeDetails;
use App\Jobs\SyncMealToCalendar;
use App\Models\GoogleCredential;
use App\Models\MealComponent;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\SimpleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assigning something to a slot has consequences beyond the slot itself: it
 * opens use-by windows (spec 4.1) and pushes items onto the grocery list
 * (spec 4.6). Keeping that in one place stops a caller doing half of it.
 */
class MealPlanner
{
    public function __construct(
        private readonly HouseholdSizeResolver $servings = new HouseholdSizeResolver,
        private readonly UseByWindowTracker $windows = new UseByWindowTracker,
        private readonly GroceryListBuilder $grocery = new GroceryListBuilder,
    ) {}

    /**
     * Find or create the slot for a day, sized from the weekly schedule.
     */
    public function slotFor(Carbon $date, MealSlot $slot): MealPlanEntry
    {
        return MealPlanEntry::firstOrCreate(
            ['date' => $date->toDateString(), 'slot' => $slot->value],
            ['household_size_used' => $this->servings->scheduledFor($date)],
        );
    }

    /**
     * Set the main dish of a slot.
     *
     * A slot has at most one primary (spec 3), so an existing one is replaced —
     * and replacing it retracts its groceries, otherwise changing your mind
     * about dinner silently leaves the old ingredients on the list.
     */
    public function setPrimaryRecipe(Carbon $date, MealSlot $slot, Recipe $recipe, ?int $servings = null): MealComponent
    {
        return DB::transaction(function () use ($date, $slot, $recipe, $servings) {
            $entry = $this->slotFor($date, $slot);

            if ($existing = $entry->components()->where('is_primary', true)->first()) {
                $this->removeComponent($existing);
            }

            $component = $entry->components()->create([
                'component_type' => ComponentType::Recipe,
                'recipe_id' => $recipe->id,
                'is_primary' => true,
                'servings_needed' => $servings ?? $this->servings->forEntry($entry),
            ]);

            $component->setRelation('mealPlanEntry', $entry);
            $component->setRelation('recipe', $recipe);

            $this->windows->trackForComponent($component);
            $this->grocery->addForComponent($component);

            $this->requestIngredientsIfMissing($recipe);
            $this->pushToCalendar($entry);

            return $component;
        });
    }

    /**
     * Spec 4.7: the first time a recipe with missing ingredients is chosen as a
     * main, try to import them from its link.
     *
     * Queued and deferred until the transaction commits — the slot must be
     * filled instantly whether or not some recipe blog is reachable, and the
     * worker must not look for a recipe that has not been written yet.
     */
    private function requestIngredientsIfMissing(Recipe $recipe): void
    {
        if ($recipe->ingredients_status !== IngredientsStatus::NotYetAdded) {
            return;
        }

        if (($recipe->recipe_links ?? []) === []) {
            return;
        }

        ImportRecipeDetails::dispatch($recipe->id)->afterCommit();
    }

    /**
     * Add a side or extra: another recipe, or an item from the simple-item
     * library (spec 4.5). Never primary, so it does not affect ranking.
     */
    public function addSide(Carbon $date, MealSlot $slot, Recipe|SimpleItem $thing, ?int $servings = null): MealComponent
    {
        return DB::transaction(function () use ($date, $slot, $thing, $servings) {
            $entry = $this->slotFor($date, $slot);
            $isRecipe = $thing instanceof Recipe;

            $component = $entry->components()->create([
                'component_type' => $isRecipe ? ComponentType::Recipe : ComponentType::SimpleItem,
                'recipe_id' => $isRecipe ? $thing->id : null,
                'simple_item_id' => $isRecipe ? null : $thing->id,
                'is_primary' => false,
                'servings_needed' => $servings ?? $this->servings->forEntry($entry),
            ]);

            $component->setRelation('mealPlanEntry', $entry);
            $isRecipe
                ? $component->setRelation('recipe', $thing)
                : $component->setRelation('simpleItem', $thing);

            // Sides deliberately skip use-by tracking (spec 4.1) but still
            // contribute to the grocery list (spec 4.6).
            $this->grocery->addForComponent($component);
            $this->pushToCalendar($entry);

            return $component;
        });
    }

    /**
     * Remove a component and retract the groceries it added.
     */
    public function removeComponent(MealComponent $component): void
    {
        $entry = $component->mealPlanEntry;

        DB::transaction(function () use ($component, $entry) {
            $this->grocery->removeForComponent($component);
            $component->delete();

            // Emptying a slot has to reach the calendar too, or a meal that was
            // cancelled goes on sitting in everyone's diary.
            if ($entry) {
                $this->pushToCalendar($entry);
            }
        });
    }

    /**
     * Spec 4.8. Queued and deferred until commit: the slot must fill instantly
     * whether or not Google is reachable, and the worker must not read a plan
     * that has not been written yet.
     */
    private function pushToCalendar(MealPlanEntry $entry): void
    {
        if (! GoogleCredential::current()->isReady()) {
            return;
        }

        SyncMealToCalendar::dispatch($entry->id)->afterCommit();
    }

    /**
     * Pin a slot to a chosen number of servings (holidays, guests) and rescale
     * its components to match.
     */
    public function setServings(MealPlanEntry $entry, int $servings): MealPlanEntry
    {
        return DB::transaction(function () use ($entry, $servings) {
            $entry->setServings($servings);

            foreach ($entry->components()->get() as $component) {
                $component->update(['servings_needed' => $entry->household_size_used]);
                $this->grocery->removeForComponent($component);
                $component->load('recipe.ingredients', 'simpleItem', 'mealPlanEntry');
                $this->grocery->addForComponent($component);
            }

            return $entry->fresh();
        });
    }
}
