<?php

namespace App\Support;

use App\Enums\IngredientCategory;

/**
 * Guesses which category a scraped ingredient belongs to.
 *
 * The category is not cosmetic: it sets the default shelf life and decides
 * whether an ingredient gets a use-by window at all (spec 4.1), so a wrong
 * guess makes the suggestion ranker chase a use-up opportunity that does not
 * exist. Every guess is editable per ingredient.
 *
 * Deliberately keyword-based. It will be wrong sometimes; being wrong in a way
 * the user can see and correct beats being absent.
 */
class IngredientCategoryGuesser
{
    /**
     * Ordered rules — the first matching keyword wins, so the more specific
     * rules come first. A category may appear more than once at different
     * priorities, which is why this is a list of pairs rather than a map.
     *
     * @return list<array{IngredientCategory, list<string>}>
     */
    private function rules(): array
    {
        return [
            [IngredientCategory::Frozen, [
                'frozen', 'ice cream', 'popsicle',
            ]],

            // Dried and powdered forms come first because they share their
            // names with fresh ingredients. Garlic powder is not garlic, and
            // would otherwise inherit produce's six-day shelf life and start
            // generating use-by windows for a jar that keeps for a year.
            [IngredientCategory::PantryDry, [
                'powder', 'powdered', 'dried', 'sundried', 'sun-dried', 'seasoning',
                'extract', 'flakes', 'granulated', 'bouillon', 'teaspoon',
                'tablespoon', 'ground cinnamon', 'ground cumin',
            ]],

            // Condiments before drinks: "red wine vinegar" and "cooking wine"
            // are things you cook with, not things you pour.
            [IngredientCategory::Condiment, [
                'vinegar', 'cooking wine', 'rice wine', 'ketchup', 'mustard',
                'mayo', 'mayonnaise', 'soy sauce', 'hot sauce',
                'sriracha', 'vinegar', 'worcestershire', 'bbq sauce', 'barbecue sauce',
                'ranch', 'dressing', 'relish', 'horseradish', 'fish sauce', 'sesame oil',
                'olive oil', 'vegetable oil', 'canola oil', 'avocado oil', 'cooking spray',
                'honey', 'maple syrup', 'peanut butter', 'jam', 'jelly', 'pesto',
            ]],

            // After condiments, so vinegar and cooking wine are already claimed.
            // Before produce, or beer and cider inherit six days and start
            // asking to be drunk before they go off. Fruit juices are named in
            // full: a bare "juice" swallowed "lemon juice", which is an
            // ingredient rather than something you pour a glass of.
            [IngredientCategory::Beverage, [
                'beer', 'wine', 'cider', 'soda', 'cola', 'seltzer', 'sparkling water',
                'coffee', 'tea', 'lemonade', 'kombucha', 'ale', 'lager', 'ipa',
                'bourbon', 'whiskey', 'whisky', 'vodka', 'gin', 'tequila', 'rum',
                'champagne', 'prosecco', 'pilsner', 'stout', 'yuengling', 'la croix',
                'orange juice', 'apple juice', 'fruit juice', 'juice box',
            ]],

            // Before the pantry, or bread claims a year of shelf life.
            [IngredientCategory::Bakery, [
                'bread', 'bun*', 'bagel', 'baguette', 'tortilla*', 'pita', 'naan',
                'croissant', 'muffin', 'donut', 'doughnut', 'crescent', 'brioche',
                'ciabatta', 'sourdough', 'hoagie', 'sub roll', 'dinner roll',
            ]],

            // Before protein: "chicken broth" is a shelf-stable carton, not raw
            // chicken, and a three-day shelf life on it would be nonsense.
            [IngredientCategory::JarredCanned, [
                'broth', 'stock', 'canned', 'can of', 'jarred', 'tomato paste',
                'tomato sauce', 'salsa', 'coconut milk', 'olives', 'pickles',
                'capers', 'enchilada sauce', 'marinara', 'refried', 'adobo',
                'diced tomatoes', 'crushed tomatoes', 'tomato puree', 'green chiles',
            ]],

            [IngredientCategory::Dairy, [
                'milk', 'cream', 'butter', 'cheese', 'yogurt', 'yoghurt',
                'half and half', 'half & half', 'creme fraiche', 'mozzarella',
                'parmesan', 'cheddar', 'ricotta', 'feta', 'egg', 'buttermilk',
                'mascarpone', 'gouda', 'provolone',
            ]],

            [IngredientCategory::Protein, [
                'chicken', 'beef', 'pork', 'turkey', 'lamb', 'bacon', 'sausage',
                'steak', 'ground', 'mince', 'shrimp', 'prawn', 'salmon', 'tuna',
                'fish', 'cod', 'tilapia', 'ham', 'chorizo', 'pepperoni', 'brisket',
                'ribs', 'tenderloin', 'thigh', 'breast', 'drumstick', 'scallop',
                'crab', 'lobster', 'tofu', 'venison', 'meatball',
            ]],

            [IngredientCategory::Produce, [
                'onion', 'garlic', 'tomato', 'lettuce', 'spinach', 'kale', 'carrot',
                // Deliberately not a bare "pepper": that matches black pepper
                // and "salt and pepper", which are pantry seasonings.
                'celery', 'bell pepper', 'red pepper', 'green pepper', 'poblano',
                'serrano', 'jalapeno', 'jalapeño', 'cucumber',
                'zucchini', 'squash', 'broccoli', 'cauliflower', 'mushroom', 'potato',
                'avocado', 'lime', 'lemon', 'cilantro', 'parsley',
                'basil', 'thyme', 'rosemary', 'ginger', 'scallion', 'green onion',
                'shallot', 'cabbage', 'corn', 'peas', 'green bean', 'asparagus',
                'apple', 'banana', 'berry', 'berries', 'strawberr', 'blueberr',
                'grape', 'orange', 'pineapple', 'mango', 'peach', 'pear', 'melon',
                'cranberr', 'raspberr', 'herb', 'leek', 'radish', 'beet', 'eggplant',
            ]],

            [IngredientCategory::PantryDry, [
                'flour', 'sugar', 'salt', 'pepper', 'peppercorn', 'baking powder',
                'baking soda', 'rice', 'pasta', 'noodle', 'bread', 'breadcrumb',
                'oat', 'quinoa', 'lentil', 'bean', 'chickpea', 'cornstarch', 'cocoa',
                'vanilla', 'cinnamon', 'cumin', 'paprika', 'oregano', 'chili powder',
                'curry', 'turmeric', 'nutmeg', 'yeast', 'gelatin', 'cereal', 'cracker',
                'tortilla', 'taco shell', 'bun', 'spice', 'stuffing', 'almond',
                'walnut', 'pecan', 'cashew', 'peanut', 'sesame seed', 'chocolate chip',
                'panko', 'broth concentrate',
            ]],
        ];
    }

    public function guess(string $ingredientName): IngredientCategory
    {
        // Unknown ingredients are treated as produce: the shortest shelf life of
        // the perishable categories. Erring toward perishable means an unknown
        // ingredient still takes part in use-up suggestions, which is the safer
        // failure — the alternative silently excludes it forever.
        return $this->guessOrNull($ingredientName) ?? IngredientCategory::Produce;
    }

    /**
     * The same match, but honest about a miss.
     *
     * Callers that need to distinguish "this is produce" from "I have no idea"
     * use this — the aisle guesser files a genuine unknown under Other, which
     * prompts a human, rather than quietly shelving it with the vegetables.
     */
    public function guessOrNull(string $ingredientName): ?IngredientCategory
    {
        $name = mb_strtolower($ingredientName);

        foreach ($this->rules() as [$category, $keywords]) {
            foreach ($keywords as $keyword) {
                if ($this->matches($name, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Whole-word matching, with stems marked explicitly by a trailing asterisk.
     *
     * A plain substring has a family of traps in it — "ale" inside "kale",
     * "ham" inside "graham", "oat" inside "goat" — but anchoring only the front
     * is not enough either: "tea" still matches "teaspoon", which filed a
     * measurement of salt under drinks. Both ends are anchored by default, and
     * anything genuinely meant as a prefix says so: "berr*" matches "berries".
     */
    private function matches(string $name, string $keyword): bool
    {
        if (str_ends_with($keyword, '*')) {
            return (bool) preg_match('/\b'.preg_quote(rtrim($keyword, '*'), '/').'/u', $name);
        }

        // A trailing plural is allowed, or anchoring both ends would quietly
        // break most of the table: "carrot" would stop matching "carrots".
        return (bool) preg_match('/\b'.preg_quote($keyword, '/').'(?:s|es)?\b/u', $name);
    }
}
