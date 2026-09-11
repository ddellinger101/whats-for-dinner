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
                // Ahead of the drinks rule, where "soda" was claiming baking
                // soda and filing it with the beer.
                'baking soda', 'baking powder', 'bicarbonate', 'baking',
                // Sugar substitutes, by their names as much as by what they
                // are — a bag of Swerve is baking goods, not produce.
                'sweetener', 'monkfruit', 'monk fruit', 'erythritol', 'stevia',
                'swerve', 'allulose', 'xylitol', 'cocoa',
                // A packet of dry mix, which is neither the soup nor the dip
                // it makes. Ahead of the tin rule, so an onion soup mix is not
                // read as a tin of soup.
                'soup mix', 'dip mix', 'seasoning mix', 'gravy mix',
                // Grains and dry pasta. "Noodle" is here rather than lower
                // down because egg noodles were being read as dairy, on the
                // egg.
                'barley', 'noodle', 'couscous', 'quinoa', 'bulgur', 'farro',
                'orzo', 'polenta', 'grits',
                // A bag of crispy fried onions keeps for months on a shelf;
                // the produce rule was claiming it on the onion.
                'onion strings', 'crispy onion', 'fried onion', 'salad topper',
                'crouton', 'breadcrumb', 'panko',
            ]],

            /*
             * The bar before anything else, because almost everything on it is
             * claimed by a later rule: simple syrup by "syrup", club soda and
             * tonic by the drinks list, cocktail cherries by the jar rule.
             *
             * "Cocktail" on its own is deliberately absent — cocktail sauce is
             * a condiment for prawns and has no business here — and so is
             * "sherry", which would take the sherry vinegar with it.
             */
            [IngredientCategory::Bar, BarKeywords::all()],

            // Cheese before condiments, because cheese is named after whatever
            // it was flavoured with: horseradish cheddar was filed as a
            // condiment on the horseradish, and garlic herb cheese would have
            // gone the same way. Matched on the word, so cheesecake is still
            // pudding.
            [IngredientCategory::Dairy, [
                'cheese', 'cheddar', 'mozzarella', 'parmesan', 'provolone',
                'gouda', 'brie', 'feta', 'ricotta', 'gruyere', 'boursin',
            ]],

            // Condiments before drinks: "red wine vinegar" and "cooking wine"
            // are things you cook with, not things you pour.
            [IngredientCategory::Condiment, [
                'vinegar', 'cooking wine', 'rice wine', 'ketchup', 'mustard',
                'mayo', 'mayonnaise', 'soy sauce', 'hot sauce',
                'sriracha', 'tabasco', 'vinegar', 'worcestershire', 'bbq sauce', 'barbecue sauce',
                // Chillies packed in oil, which the seasoning rule below was
                // claiming on the word "pepper" and filing with the dry spices.
                'calabrian', 'chili crisp', 'chili paste', 'harissa', 'gochujang',
                'ranch', 'dressing', 'relish', 'horseradish', 'fish sauce',
                // Any oil, not a list of them. Naming five meant grapeseed oil
                // fell through to produce on the word "grape", and the next
                // unlisted oil would have done the same. Matched on a word
                // boundary, so "shrimp boil" is untouched.
                'oil', 'cooking spray',
                // Jars you spread from. "Butter" alone means dairy, so each
                // of these has to say what kind it is — sunflower seed butter
                // was being filed with the milk.
                'honey', 'peanut butter', 'seed butter', 'sunflower butter',
                'almond butter', 'cashew butter', 'nut butter', 'hazelnut spread',
                'chocolate spread', 'nutella', 'jam', 'jelly', 'pesto',
                // Any syrup, for the same reason as any oil: naming maple and
                // leaving table, simple and corn syrup to fall through to
                // produce is a list that only covers what someone thought of.
                // Cocktail syrups included — they live in the fridge with the
                // rest, whatever they are for.
                'syrup', 'grenadine', 'orgeat', 'agave nectar',
                // A bottle of steak sauce is not steak. Condiments are checked
                // before protein, so naming it here is enough.
                'steak sauce', 'cocktail sauce', 'chili sauce', 'sweet and sour',
                'tartar sauce', 'aioli', 'heinz 57', 'dressing',
            ]],

            // After condiments, so vinegar and cooking wine are already claimed.
            // Before produce, or beer and cider inherit six days and start
            // asking to be drunk before they go off. Fruit juices are named in
            // full: a bare "juice" swallowed "lemon juice", which is an
            // ingredient rather than something you pour a glass of.
            [IngredientCategory::Beverage, [
                'beer', 'wine', 'cider', 'soda', 'cola', 'seltzer', 'sparkling water',
                'coffee', 'tea', 'lemonade', 'kombucha', 'ale', 'lager', 'ipa',
                // The spirits moved to the bar rule above; what is left here is
                // what you would pour and drink as it comes.
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
                // A tin that says nothing about being a tin. Each of these was
                // being read as the fresh thing or the dairy thing: condensed
                // milk as milk with twelve days on it, cranberry sauce as
                // fruit, cream of chicken as poultry.
                'cranberry sauce', 'condensed milk', 'evaporated milk',
                'coconut cream', 'cream of chicken', 'cream of mushroom',
                'cream of celery', 'condensed soup', 'pumpkin pie mix',
                'pumpkin puree', 'cut green beans', 'water chestnuts',
                // Beans come in a tin in this kitchen. Named one by one rather
                // than as "beans", because green beans are produce and the
                // early dried rule still catches "dried black beans".
                'garbanzo', 'chickpea', 'chick pea', 'black beans', 'white beans',
                'great northern beans', 'kidney beans', 'pinto beans',
                'cannellini', 'navy beans', 'butter beans', 'baked beans',
                'chili beans', 'chili-style beans', 'red beans',
                // Things that live in a jar on the shelf rather than in the
                // fruit bowl: cocktail cherries are not cherries, and pickled
                // ginger is not ginger.
                'pickled', 'maraschino', 'cocktail cherries', 'cocktail onions',
                'artichoke hearts', 'roasted red peppers',
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

        /*
         * The spice rack is dry goods by definition, and it has to be settled
         * before any keyword gets a look. Left to the rules, "Cumin ground"
         * and "Nutmeg ground" were filed as Protein on the word "ground" —
         * inheriting a three-day shelf life — and "Thyme leaves" and "Crushed
         * red pepper" as Produce on six days, which would have put the whole
         * rack in the use-these-up list within a week.
         *
         * Anything a recipe calls fresh is not a staple, so it falls through
         * to the rules below and is filed as the produce it is.
         */
        if (PantryStaples::match($name)) {
            return IngredientCategory::PantryDry;
        }

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
        return KeywordMatch::matches($name, $keyword);
    }
}
