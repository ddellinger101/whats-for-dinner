<?php

namespace App\Support;

use App\Enums\IngredientCategory;

/**
 * Guesses which category a scraped ingredient belongs to.
 *
 * The category is not cosmetic: it sets the default shelf life and decides
 * whether an ingredient gets a use-by window at all (spec 4.1), so a scraped
 * ingredient with no category would quietly opt out of the feature the app
 * exists for. Every guess is editable per ingredient.
 *
 * Deliberately keyword-based. It will be wrong sometimes; being wrong in a way
 * the user can see and correct beats being absent.
 */
class IngredientCategoryGuesser
{
    /**
     * Order matters: the first category with a matching keyword wins, so the
     * more specific lists come before the broad ones.
     *
     * @var array<string, list<string>>
     */
    private const KEYWORDS = [
        IngredientCategory::Frozen->value => [
            'frozen', 'ice cream', 'popsicle',
        ],
        IngredientCategory::Condiment->value => [
            'ketchup', 'mustard', 'mayo', 'mayonnaise', 'soy sauce', 'hot sauce',
            'sriracha', 'vinegar', 'worcestershire', 'bbq sauce', 'barbecue sauce',
            'ranch', 'dressing', 'relish', 'horseradish', 'fish sauce', 'sesame oil',
            'olive oil', 'vegetable oil', 'canola oil', 'cooking spray', 'honey',
            'maple syrup', 'peanut butter', 'jam', 'jelly', 'pesto',
        ],
        IngredientCategory::Dairy->value => [
            'milk', 'cream', 'butter', 'cheese', 'yogurt', 'yoghurt', 'sour cream',
            'half and half', 'creme fraiche', 'mozzarella', 'parmesan', 'cheddar',
            'ricotta', 'feta', 'egg', 'eggs', 'buttermilk', 'mascarpone', 'gouda',
            'provolone', 'cottage cheese', 'cream cheese',
        ],
        IngredientCategory::Protein->value => [
            'chicken', 'beef', 'pork', 'turkey', 'lamb', 'bacon', 'sausage',
            'steak', 'ground', 'mince', 'shrimp', 'prawn', 'salmon', 'tuna',
            'fish', 'cod', 'tilapia', 'ham', 'chorizo', 'pepperoni', 'brisket',
            'ribs', 'tenderloin', 'thigh', 'breast', 'drumstick', 'scallop',
            'crab', 'lobster', 'tofu', 'venison', 'meatball',
        ],
        IngredientCategory::JarredCanned->value => [
            'canned', 'can of', 'jarred', 'tomato paste', 'tomato sauce', 'salsa',
            'broth', 'stock', 'coconut milk', 'olives', 'pickles', 'capers',
            'enchilada sauce', 'marinara', 'refried', 'chipotle in adobo',
            'diced tomatoes', 'crushed tomatoes', 'tomato puree',
        ],
        IngredientCategory::Produce->value => [
            'onion', 'garlic', 'tomato', 'lettuce', 'spinach', 'kale', 'carrot',
            // Deliberately not a bare "pepper": that matches black pepper and
            // "salt and pepper", which are pantry seasonings, not produce.
            'celery', 'bell pepper', 'red pepper', 'green pepper', 'poblano',
            'serrano', 'jalapeno', 'jalapeño', 'cucumber',
            'zucchini', 'squash', 'broccoli', 'cauliflower', 'mushroom', 'potato',
            'sweet potato', 'avocado', 'lime', 'lemon', 'cilantro', 'parsley',
            'basil', 'thyme', 'rosemary', 'ginger', 'scallion', 'green onion',
            'shallot', 'cabbage', 'corn', 'peas', 'green bean', 'asparagus',
            'apple', 'banana', 'berry', 'berries', 'strawberr', 'blueberr',
            'grape', 'orange', 'pineapple', 'mango', 'peach', 'pear', 'melon',
            'cranberr', 'raspberr', 'herb', 'leek', 'radish', 'beet', 'eggplant',
        ],
        IngredientCategory::PantryDry->value => [
            'flour', 'sugar', 'salt', 'pepper', 'peppercorn', 'baking powder', 'baking soda',
            'rice', 'pasta', 'noodle', 'bread', 'breadcrumb', 'oat', 'quinoa',
            'lentil', 'bean', 'chickpea', 'cornstarch', 'cocoa', 'vanilla',
            'cinnamon', 'cumin', 'paprika', 'oregano', 'chili powder', 'curry',
            'turmeric', 'nutmeg', 'yeast', 'gelatin', 'cereal', 'cracker',
            'tortilla', 'taco shell', 'bun', 'seasoning', 'spice', 'stuffing',
            'almond', 'walnut', 'pecan', 'cashew', 'peanut', 'sesame seed',
            'chocolate chip', 'powdered sugar', 'brown sugar', 'panko',
        ],
    ];

    public function guess(string $ingredientName): IngredientCategory
    {
        $name = mb_strtolower($ingredientName);

        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return IngredientCategory::from($category);
                }
            }
        }

        // Unknown ingredients are treated as produce: the shortest shelf life of
        // the perishable categories. Erring toward perishable means an unknown
        // ingredient still takes part in use-up suggestions, which is the safer
        // failure — the alternative silently excludes it forever.
        return IngredientCategory::Produce;
    }
}
