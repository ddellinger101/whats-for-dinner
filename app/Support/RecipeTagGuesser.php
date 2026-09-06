<?php

namespace App\Support;

use App\Enums\CategoryTag;

/**
 * Infers category tags for a recipe from its name, ingredients and link.
 *
 * The archive arrived with no tags at all, and hand-tagging 143 dishes is the
 * kind of chore that never gets done. Being roughly right and correctable beats
 * an empty field: a wrong tag is visible and takes one tap to remove, whereas
 * no tags means the recipe browser's filters do nothing.
 *
 * Tags are additive by design — "Beef Tacos" is Mexican, a taco night, and
 * whatever else fits.
 */
class RecipeTagGuesser
{
    /**
     * Matched against the recipe name and its link slug, where the strongest
     * signal lives. Order is irrelevant: every match is collected.
     *
     * @return array<string, list<string>>
     */
    private function nameKeywords(): array
    {
        return [
            CategoryTag::Mexican->value => [
                'taco', 'burrito', 'enchilada', 'fajita', 'quesadilla', 'salsa',
                'carnitas', 'tostada', 'nacho', 'chimichanga', 'queso', 'elote',
                'street corn', 'pico de gallo', 'tex mex', 'tex-mex', 'mexican',
                'barbacoa', 'birria', 'tamale', 'churro', 'chile relleno', 'fiesta',
            ],
            CategoryTag::Italian->value => [
                'pasta', 'spaghetti', 'lasagna', 'lasagne', 'alfredo', 'parmigiana',
                'parm', 'pizza', 'risotto', 'gnocchi', 'marinara', 'bolognese',
                'pesto', 'carbonara', 'ravioli', 'italian', 'bruschetta', 'caprese',
                'ziti', 'fettuccine', 'penne', 'tuscan', 'piccata', 'scampi',
                'calzone', 'stromboli', 'tortellini', 'orzo', 'primavera',
                'sausage and peppers', 'bruschetta', 'focaccia',
            ],
            // Tags are additive, so this list must avoid vocabulary Mexican
            // also owns — "chorizo" and "empanada" are deliberately absent,
            // since a dish using either would come out tagged both.
            CategoryTag::Spanish->value => [
                'spanish', 'paella', 'gazpacho', 'patatas bravas', 'romesco',
                'manchego', 'albondigas', 'jamon', 'serrano ham', 'pisto',
                'sangria', 'pan con tomate', 'tortilla espanola',
                'tortilla española', 'catalan', 'basque', 'andalusian',
            ],
            CategoryTag::Asian->value => [
                // The cuisine's own name has to be in its own list. Without it
                // "Polynesian Chicken" went untagged, which is an absurd miss.
                'asian', 'stir fry', 'stir-fry', 'teriyaki', 'sushi', 'ramen', 'lo mein',
                'chow mein', 'fried rice', 'hoisin', 'orange chicken', 'general tso',
                'pad thai', 'curry', 'dumpling', 'egg roll', 'spring roll', 'bang bang',
                'sesame chicken', 'korean', 'thai', 'chinese', 'japanese', 'vietnamese',
                'banh mi', 'bulgogi', 'katsu', 'miso', 'kung pao', 'satay', 'potsticker',
                'wonton', 'gyoza', 'lettuce wrap', 'crab rangoon', 'tikka', 'masala',
            ],
            CategoryTag::Polynesian->value => [
                'polynesian', 'hawaiian', 'pineapple', 'kalua', 'luau', 'huli', 'poke',
                'macadamia', 'musubi', 'ahi', 'seaweed salad', 'tropical',
            ],
            CategoryTag::American->value => [
                'american', 'london broil', 'shrimp boil', 'crawfish boil', 'po boy',
                'burger', 'hot dog', 'meatloaf', 'mac and cheese', 'macaroni and cheese',
                'pot roast', 'sloppy joe', 'fried chicken', 'bbq', 'barbecue', 'philly',
                'cheesesteak', 'buffalo', 'ranch', 'corn dog', 'biscuits and gravy',
                'chicken fried', 'pulled pork', 'brisket', 'jambalaya', 'gumbo', 'cajun',
                'big mac', 'sliders', 'chili dog', 'cornbread', 'pot pie',
            ],
            CategoryTag::Soup->value => [
                'soup', 'chowder', 'chili', 'stew', 'bisque', 'broth', 'gumbo', 'pho',
            ],
            CategoryTag::Salad->value => [
                'salad', 'slaw', 'caesar',
            ],
            CategoryTag::ComfortFood->value => [
                'casserole', 'meatloaf', 'mac and cheese', 'macaroni', 'pot pie',
                'shepherd', 'pot roast', 'gravy', 'biscuit', 'mashed', 'dumpling',
                'stuffed', 'skillet', 'bake', 'stroganoff', 'french onion',
            ],
            CategoryTag::Holiday->value => [
                'thanksgiving', 'christmas', 'easter', 'holiday', 'stuffing',
                'cranberry', 'prime rib', 'glazed ham', 'green bean casserole',
            ],
            CategoryTag::Grilling->value => [
                'grill', 'grilled', 'bbq', 'barbecue', 'kebab', 'kabob', 'skewer',
                'smoked', 'hasselback', 'steak',
            ],
            CategoryTag::Appetizer->value => [
                'dip', 'wings', 'appetizer', 'bites', 'poppers', 'bruschetta',
                'nacho', 'meatball', 'deviled', 'charcuterie',
            ],
            CategoryTag::Dessert->value => [
                // Deliberately no bare "pie": pot pie and shepherd's pie are dinner.
                'cake', 'cookie', 'brownie', 'cheesecake', 'pudding', 'ice cream',
                'cobbler', 'tart', 'mousse', 'apple pie', 'pumpkin pie', 'pecan pie',
                'dessert', 'fudge', 'truffle',
            ],
            // Bought ready to eat, needing no ingredients. Deliberately not
            // "sandwich" or "wrap": those are usually assembled at home from a
            // shopping list, which is the opposite of what this tag means.
            CategoryTag::ReadyToEat->value => [
                'rotisserie', 'deli', 'cold cut', 'takeout', 'take out',
                'store bought', 'store-bought', 'pre-made', 'premade', 'frozen pizza',
                'lunchable', 'charcuterie',
            ],
            CategoryTag::Tapas->value => [
                'tapas', 'small plate',
            ],
            CategoryTag::Keto->value => [
                'keto', 'low carb', 'low-carb',
            ],
        ];
    }

    /**
     * Weaker, ingredient-level signals. Only cuisines are inferred this way:
     * a dish built on tortillas and cumin is Mexican even when its name says
     * nothing, but ingredients say little about whether something is comfort
     * food or an appetiser.
     *
     * @return array<string, list<string>>
     */
    private function ingredientKeywords(): array
    {
        return [
            CategoryTag::Mexican->value => [
                'tortilla', 'taco seasoning', 'enchilada sauce', 'salsa', 'cotija',
                'chipotle', 'poblano', 'queso fresco', 'refried',
            ],
            CategoryTag::Italian->value => [
                'parmesan', 'mozzarella', 'marinara', 'ricotta', 'basil', 'pancetta',
                'italian seasoning', 'prosciutto',
            ],
            // Saffron and manchego are shared with nothing else the
            // archive cooks; smoked paprika is deliberately absent,
            // since American barbecue rubs lean on it just as hard.
            CategoryTag::Spanish->value => [
                'saffron', 'manchego', 'piquillo', 'sherry vinegar',
            ],
            CategoryTag::Asian->value => [
                'soy sauce', 'sesame oil', 'hoisin', 'rice vinegar', 'fish sauce',
                'oyster sauce', 'mirin', 'gochujang', 'curry paste', 'water chestnut',
            ],
            CategoryTag::Polynesian->value => [
                'coconut milk', 'macadamia',
            ],
        ];
    }

    /**
     * @param  list<string>  $ingredientNames
     * @param  list<string>  $links
     * @return list<CategoryTag>
     */
    public function guess(string $recipeName, array $ingredientNames = [], array $links = []): array
    {
        // The link slug carries the site's own title, which is often more
        // descriptive than the shorthand name in the spreadsheet.
        $slugs = implode(' ', array_map(
            fn (string $url) => str_replace(['-', '_', '/'], ' ', (string) parse_url($url, PHP_URL_PATH)),
            $links,
        ));

        $nameHaystack = mb_strtolower($recipeName.' '.$slugs);
        $ingredientHaystack = mb_strtolower(implode(' | ', $ingredientNames));

        $found = [];

        foreach ($this->nameKeywords() as $tag => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($nameHaystack, $keyword)) {
                    $found[$tag] = true;
                    break;
                }
            }
        }

        foreach ($this->ingredientKeywords() as $tag => $keywords) {
            if (isset($found[$tag])) {
                continue;
            }

            foreach ($keywords as $keyword) {
                if (str_contains($ingredientHaystack, $keyword)) {
                    $found[$tag] = true;
                    break;
                }
            }
        }

        return array_values(array_map(
            fn (string $value) => CategoryTag::from($value),
            array_keys($found),
        ));
    }
}
