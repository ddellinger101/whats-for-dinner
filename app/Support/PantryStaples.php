<?php

namespace App\Support;

/**
 * The spice rack: things the household always has, which should never reach
 * the grocery list on a recipe's say-so.
 *
 * Transcribed from a photograph of the actual rack, so the names here are the
 * labels on the jars. Recipes write them a dozen other ways — "ground cumin",
 * "cumin", "cumin ground" — which is what the aliases are for: without them
 * each spelling becomes its own ingredient row and each row starts generating
 * grocery lines again.
 *
 * Two rules keep this from swallowing things it should not:
 *
 *  - Matching is whole-name, never substring. "Garlic cloves" is not "cloves",
 *    and "yellow mustard" (the squeeze bottle) is not "yellow mustard seed".
 *  - Anything a recipe calls fresh is never a staple, however it is spelled.
 *    A jar of dried thyme does not satisfy "1/4 cup fresh thyme", so that line
 *    still belongs on the list, where the household can decide whether to buy
 *    it or reach for the jar.
 *
 * Deliberately absent: garlic, onion and red pepper on their own. Those name
 * the fresh bulb or the bell pepper far more often than the jar, and wrongly
 * suppressing real produce is a worse failure than an extra line of paprika.
 */
class PantryStaples
{
    /** @var list<PantryStaple>|null */
    private static ?array $cache = null;

    /** @return list<PantryStaple> */
    public static function all(): array
    {
        return self::$cache ??= [
            // ---------------------------------------------------------- herbs
            // Every one of these is better fresh, and a recipe asking only for
            // "basil" has not said which it wants — see betterFresh below.
            new PantryStaple('Basil leaves', 'herb', [
                'basil', 'dried basil', 'dried basil leaves',
            ], betterFresh: true),
            new PantryStaple('Bay leaves', 'herb', [
                'bay leaf', 'dried bay leaves', 'dried bay leaf',
            ]),
            new PantryStaple('Oregano leaves', 'herb', [
                'oregano', 'dried oregano', 'dried oregano leaves',
            ], betterFresh: true),
            new PantryStaple('Parsley flakes', 'herb', [
                'parsley', 'dried parsley', 'dried parsley flakes', 'parsley flake',
            ], betterFresh: true),
            new PantryStaple('Rosemary leaves', 'herb', [
                'rosemary', 'dried rosemary', 'dried rosemary leaves',
            ], betterFresh: true),
            new PantryStaple('Thyme leaves', 'herb', [
                'thyme', 'dried thyme', 'thyme dried', 'dried thyme leaves',
            ], betterFresh: true),

            // --------------------------------------------------------- spices
            new PantryStaple('Allspice', 'spice', ['whole allspice']),
            new PantryStaple('Allspice ground', 'spice', ['ground allspice']),
            new PantryStaple('Ancho chili powder', 'spice', ['ancho powder', 'ancho chile powder']),
            new PantryStaple('Cardamom ground', 'spice', ['cardamom', 'ground cardamom']),
            new PantryStaple('Cayenne pepper', 'spice', ['cayenne', 'ground cayenne', 'cayenne powder']),
            new PantryStaple('Chili powder', 'spice', ['chilli powder', 'chile powder']),
            new PantryStaple('Cinnamon', 'spice', ['ground cinnamon', 'cinnamon ground']),
            new PantryStaple('Cinnamon sticks', 'spice', ['cinnamon stick', 'whole cinnamon']),
            new PantryStaple('Cloves', 'spice', ['whole cloves']),
            new PantryStaple('Cloves ground', 'spice', ['ground cloves', 'ground clove']),
            // "Red pepper flakes" is the form the archive actually uses. Bare
            // "red pepper" is left out on purpose, being a bell pepper as often
            // as not.
            new PantryStaple('Crushed red pepper', 'spice', [
                'red pepper flakes', 'crushed red pepper flakes', 'red chili flakes',
                'chili flakes', 'crushed chili flakes',
            ]),
            new PantryStaple('Cumin ground', 'spice', ['cumin', 'ground cumin']),
            new PantryStaple('Garlic powder', 'spice'),
            new PantryStaple('Granulated garlic', 'spice'),
            new PantryStaple('Minced onion', 'spice', ['dried minced onion', 'onion flakes', 'dried onion']),
            new PantryStaple('Mustard ground', 'spice', [
                'ground mustard', 'dry mustard', 'mustard powder', 'dried mustard powder',
            ]),
            new PantryStaple('Nutmeg ground', 'spice', ['nutmeg', 'ground nutmeg']),
            new PantryStaple('Onion powder', 'spice'),
            // Smoked paprika is a different jar and is not on the rack, so it
            // stays on the list.
            new PantryStaple('Paprika', 'spice', ['sweet paprika']),
            // Not in the photograph, which shows the rack rather than what
            // stands beside the hob, but always in. The dozen ways the archive
            // writes these are handled by saltAndPepper() rather than listed
            // here — see the note there.
            new PantryStaple('Salt', 'spice', [
                'kosher salt', 'sea salt', 'table salt', 'fine salt',
                'coarse salt', 'fine sea salt', 'coarse sea salt',
            ]),
            new PantryStaple('Black pepper', 'spice', [
                'pepper', 'ground black pepper', 'ground pepper', 'cracked black pepper',
                'black cracked pepper', 'coarse black pepper', 'cracked pepper',
            ]),
            new PantryStaple('Sesame seed', 'spice', ['sesame seeds', 'toasted sesame seeds']),
            new PantryStaple('Star anise', 'spice', ['whole star anise']),
            new PantryStaple('White pepper', 'spice', ['ground white pepper', 'white pepper ground']),
            new PantryStaple('Yellow mustard seed', 'spice', ['mustard seed', 'mustard seeds']),

            // --------------------------------------------------------- blends
            new PantryStaple('Adobo seasoning', 'blend', ['adobo']),
            new PantryStaple('BBQ seasoning', 'blend', ['barbecue seasoning', 'bbq rub']),
            new PantryStaple('Cajun seasoning', 'blend', ['cajun spice', 'cajun']),
            new PantryStaple('Cinnamon sugar', 'blend'),
            new PantryStaple('Complete seasoning', 'blend'),
            new PantryStaple('Garlic salt', 'blend'),
            new PantryStaple('Italian seasoning', 'blend', ['italian herb seasoning']),
            new PantryStaple('Lemon pepper seasoning', 'blend', ['lemon pepper']),
            new PantryStaple('Pork rub', 'blend'),
            new PantryStaple('Pumpkin pie spice', 'blend'),
            new PantryStaple('Taco seasoning', 'blend', ['taco seasoning mix']),

            // Not a jar, so it is never stocked on the rack — but recipes name
            // it constantly and it belongs off the list like the two jars it
            // stands for.
            new PantryStaple('Salt and pepper', 'blend', onRack: false),
        ];
    }

    /**
     * The staple a written ingredient name refers to, or null.
     *
     * Whole-name only. Anything called fresh is never a staple.
     */
    public static function match(string $name): ?PantryStaple
    {
        $needle = self::normalise($name);

        if ($needle === '' || self::saysFresh($needle)) {
            return null;
        }

        foreach (self::all() as $staple) {
            if (in_array($needle, $staple->allNames(), true)) {
                return $staple;
            }
        }

        return self::saltAndPepper($needle);
    }

    /**
     * Salt and pepper, however the recipe dressed it up.
     *
     * The archive writes these sixteen ways — "salt and pepper", "salt/pepper",
     * "kosher salt and fresh ground black pepper", "salt and plenty of black
     * pepper" — and listing every one would still miss the seventeenth. So the
     * qualifiers are stripped instead, and whatever is left has to be nothing
     * but salt and pepper.
     *
     * The qualifier list is deliberately short. "White", "red", "lemon" and
     * "bell" are absent, so white pepper keeps its own jar and neither red
     * pepper nor a bell pepper is ever mistaken for the pepper mill.
     */
    private static function saltAndPepper(string $name): ?PantryStaple
    {
        $qualifiers = [
            'kosher', 'sea', 'table', 'fine', 'coarse', 'coarsely', 'ground',
            'cracked', 'freshly', 'fresh', 'black', 'plenty', 'of', 'and',
            'plus', 'more', 'extra', 'to', 'taste', 'each', 'with', 'a', 'good',
        ];

        $words = preg_split('/[^a-z]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $core = array_values(array_unique(array_diff($words, $qualifiers)));

        if ($core === [] || array_diff($core, ['salt', 'pepper']) !== []) {
            return null;
        }

        $name = match (true) {
            count($core) === 2 => 'Salt and pepper',
            $core[0] === 'salt' => 'Salt',
            default => 'Black pepper',
        };

        foreach (self::all() as $staple) {
            if ($staple->name === $name) {
                return $staple;
            }
        }

        return null;
    }

    /**
     * Whether a recipe naming this has left the fresh-or-dried choice open and
     * would be the better for fresh. Drives the hint on the recipe page.
     */
    public static function betterFresh(string $name): bool
    {
        return self::match($name)?->betterFresh ?? false;
    }

    /**
     * "Fresh" as a whole word anywhere in the name — "fresh thyme", "thyme,
     * fresh", "fresh basil leaves". Not "freshly", which describes the
     * grinding rather than the ingredient.
     */
    private static function saysFresh(string $name): bool
    {
        // "Fresh ground black pepper" and "freshly grated nutmeg" describe the
        // grinding, not the ingredient — there is no fresh form of a
        // peppercorn, and the cook is grinding the jar. Only a bare "fresh" is
        // a request for the fresh thing.
        $name = preg_replace(
            // Grinding words only. "Fresh chopped parsley" and "fresh minced
            // basil" are requests for the fresh herb, and must keep their
            // "fresh".
            '/\bfresh(?:ly)?\s+(ground|grated|cracked|milled)\b/u',
            '$1',
            $name,
        ) ?? $name;

        return (bool) preg_match('/\bfresh\b/u', $name);
    }

    private static function normalise(string $name): string
    {
        $name = mb_strtolower(trim($name));
        // Trailing punctuation the parser leaves behind on some sites.
        $name = preg_replace('/[.,;:]+$/u', '', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}
