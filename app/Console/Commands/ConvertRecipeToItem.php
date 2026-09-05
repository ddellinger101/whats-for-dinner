<?php

namespace App\Console\Commands;

use App\Enums\ComponentType;
use App\Enums\MealTypeHint;
use App\Models\MealComponent;
use App\Models\Recipe;
use App\Models\SimpleItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns a recipe into a simple item.
 *
 * Some rows in the archive were never recipes: "Leftovers" has no ingredients,
 * no rating worth keeping and no protein, but it is still something you plan
 * for a Tuesday. As a recipe it clutters the suggestion ranker; as a simple
 * item it is a one-tap choice for any slot, which is what it always was.
 */
class ConvertRecipeToItem extends Command
{
    protected $signature = 'recipes:to-item
        {name : The recipe name to convert}
        {--hint=dinner : Quick-pick grouping: breakfast, lunch, dinner or dinner_side}';

    protected $description = 'Convert a recipe into a reusable simple item';

    public function handle(): int
    {
        $recipe = Recipe::where('name', $this->argument('name'))->first();

        if (! $recipe) {
            $this->error("No recipe named \"{$this->argument('name')}\".");

            return self::FAILURE;
        }

        $hint = MealTypeHint::tryFrom((string) $this->option('hint'));

        if (! $hint) {
            $this->error('Unknown hint. Use breakfast, lunch, dinner or dinner_side.');

            return self::FAILURE;
        }

        if ($recipe->ingredients()->exists()) {
            $this->warn("{$recipe->name} has ingredients recorded. Converting will discard them.");

            if (! $this->confirm('Continue?', false)) {
                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($recipe, $hint) {
            $item = SimpleItem::firstOrCreate(
                ['name' => $recipe->name],
                ['meal_type_hint' => $hint, 'breakdown_prompted' => true],
            );

            // Anything already planned keeps its place in the week; only what
            // it points at changes.
            MealComponent::where('recipe_id', $recipe->id)->update([
                'component_type' => ComponentType::SimpleItem->value,
                'recipe_id' => null,
                'simple_item_id' => $item->id,
                'is_primary' => false,
            ]);

            $recipe->ingredients()->detach();
            $recipe->delete();

            $this->info("Converted \"{$item->name}\" into a simple item ({$hint->label()}).");
        });

        return self::SUCCESS;
    }
}
