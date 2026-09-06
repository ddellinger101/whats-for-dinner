<?php

namespace App\Support;

use App\Enums\GroceryAisle;
use App\Enums\IngredientCategory;
use App\Models\GroceryListItem;
use App\Models\Ingredient;

/**
 * Decides which aisle a grocery line belongs under.
 *
 * Tried in order of how much the app actually knows:
 *
 *   1. What this exact item was filed under last time. A correction made by
 *      hand teaches the list, so the same typo-prone item never has to be
 *      re-sorted twice.
 *   2. The ingredient record behind the line, when there is one.
 *   3. Keywords in the name.
 *
 * Only then does it give up and answer Other, which is a visible prompt to
 * tag it rather than a silent miscategorisation.
 */
class AisleGuesser
{
    public function __construct(
        private readonly IngredientCategoryGuesser $categories = new IngredientCategoryGuesser,
    ) {}

    /**
     * Checked before the ingredient category, because these split or override
     * it: Protein is one shelf-life class but two aisles, and bread is dry
     * pantry goods by shelf life and bakery by geography.
     *
     * @return list<array{GroceryAisle, list<string>}>
     */
    private function nameRules(): array
    {
        return [
            // Prepared food comes first. "Rotisserie chicken" contains
            // "chicken", so any later ordering files a hot counter item under
            // raw meat — the same trap that put garlic powder in produce.
            [GroceryAisle::ReadyToEat, [
                'rotisserie', 'takeout', 'take out', 'pre-made', 'premade',
                'lunchable', 'charcuterie', 'deli tray', 'ready meal',
                'prepared', 'hot bar', 'salad bar',
            ]],
            // Pantry before drinks, for the same reason the category guesser
            // puts condiments there: these are things you cook with, not
            // things you pour. Without it "red wine vinegar" is filed by the
            // wine and "baking soda" by the soda.
            [GroceryAisle::Pantry, [
                'oil', 'vinegar', 'cooking wine', 'rice wine', 'baking soda',
                'baking powder', 'extract',
            ]],
            [GroceryAisle::Drinks, [
                'beer', 'wine', 'cider', 'soda', 'cola', 'juice', 'seltzer',
                'sparkling water', 'coffee', 'tea', 'lemonade', 'kombucha',
                'ale', 'lager', 'ipa', 'bourbon', 'whiskey', 'vodka', 'gin',
                'tequila', 'rum', 'champagne', 'prosecco',
            ]],
            [GroceryAisle::Seafood, [
                'shrimp', 'prawn', 'salmon', 'tuna', 'cod', 'tilapia', 'halibut',
                // A stem, not a word: anchovy and anchovies share no plural
                // the matcher would find on its own.
                'crab', 'lobster', 'scallop', 'ahi', 'mahi', 'fish', 'anchov*',
                'calamari', 'mussel', 'clam', 'oyster', 'seafood',
            ]],
            [GroceryAisle::Bakery, [
                'bread', 'bun', 'roll', 'bagel', 'baguette', 'tortilla', 'pita',
                'naan', 'croissant', 'muffin', 'cake', 'donut', 'doughnut',
                'crescent', 'biscuit dough', 'pizza dough', 'pie crust',
            ]],
            [GroceryAisle::Meat, [
                'chicken', 'beef', 'pork', 'turkey', 'lamb', 'bacon', 'sausage',
                'steak', 'ground', 'mince', 'ham', 'chorizo', 'pepperoni',
                'brisket', 'ribs', 'tenderloin', 'thigh', 'breast', 'drumstick',
                'venison', 'meatball', 'deli meat', 'hot dog',
            ]],
            [GroceryAisle::Frozen, [
                'frozen', 'ice cream', 'popsicle',
            ]],
        ];
    }

    /**
     * Shelf-life category to aisle, for anything the name did not settle.
     */
    private function fromIngredientCategory(IngredientCategory $category): GroceryAisle
    {
        return match ($category) {
            IngredientCategory::Produce => GroceryAisle::Produce,
            IngredientCategory::Dairy => GroceryAisle::Dairy,
            IngredientCategory::Protein => GroceryAisle::Meat,
            IngredientCategory::Frozen => GroceryAisle::Frozen,
            IngredientCategory::Bakery => GroceryAisle::Bakery,
            IngredientCategory::Beverage => GroceryAisle::Drinks,
            IngredientCategory::PantryDry,
            IngredientCategory::JarredCanned,
            IngredientCategory::Condiment => GroceryAisle::Pantry,
        };
    }

    /**
     * @param  bool  $useMemory  Pass false to re-derive from scratch. A re-guess
     *                           that consulted the memory would only ever return
     *                           its own previous answer, so improved rules could
     *                           never correct an old mistake.
     */
    public function guess(string $itemName, ?Ingredient $ingredient = null, bool $useMemory = true): GroceryAisle
    {
        if ($useMemory && $remembered = $this->remembered($itemName)) {
            return $remembered;
        }

        $name = mb_strtolower($itemName);

        foreach ($this->nameRules() as [$aisle, $keywords]) {
            foreach ($keywords as $keyword) {
                if (KeywordMatch::matches($name, $keyword)) {
                    return $aisle;
                }
            }
        }

        $ingredient ??= Ingredient::whereRaw('LOWER(name) = ?', [$name])->first();

        if ($ingredient) {
            return $this->fromIngredientCategory($ingredient->category);
        }

        // A hand-typed item has no ingredient record, so fall back to the same
        // keyword table the scraper uses rather than keeping a second copy of
        // "which words mean produce". guessOrNull, not guess: an outright
        // unknown must reach Other and prompt a human, not be filed as produce.
        if ($category = $this->categories->guessOrNull($itemName)) {
            return $this->fromIngredientCategory($category);
        }

        return GroceryAisle::Other;
    }

    /**
     * The aisle this item was last filed under, ignoring anything left as
     * Other — an unsorted line is not a lesson worth learning.
     */
    private function remembered(string $itemName): ?GroceryAisle
    {
        $previous = GroceryListItem::query()
            // Cleared lines still count: tidying the list must not erase what
            // it learned about where things live.
            ->withTrashed()
            ->whereRaw('LOWER(item_name) = ?', [mb_strtolower(trim($itemName))])
            ->whereNotNull('aisle')
            ->where('aisle', '!=', GroceryAisle::Other->value)
            ->latest('created_at')
            ->first();

        return $previous?->aisle;
    }
}
