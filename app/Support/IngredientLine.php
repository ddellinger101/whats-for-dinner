<?php

namespace App\Support;

/**
 * Turns a free-text recipe ingredient line into a quantity, a unit and a name.
 *
 * Recipe sites write these for humans, not parsers: "2 1/2 cups (300g)
 * all-purpose flour, sifted". The aim is a usable grocery line and a name that
 * matches the same ingredient across recipes — not culinary precision.
 */
readonly class IngredientLine
{
    public function __construct(
        public string $raw,
        public ?float $quantity,
        public ?string $unit,
        public string $name,
    ) {}

    /**
     * Slashes that are not the slash.
     *
     * Recipe sites typeset "1/2" with U+2044 FRACTION SLASH or U+2215 DIVISION
     * SLASH, which look identical and match nothing. Normalised before the
     * quantity is read, or "1⁄2 cup" parses as a quantity of 1 and an
     * ingredient called "⁄2 cup ...".
     */
    private const SLASHES = ["\u{2044}" => '/', "\u{2215}" => '/', "\u{FF0F}" => '/'];

    /** Vulgar fractions appear constantly on recipe sites. */
    private const FRACTIONS = [
        '½' => '1/2', '⅓' => '1/3', '⅔' => '2/3', '¼' => '1/4', '¾' => '3/4',
        '⅕' => '1/5', '⅖' => '2/5', '⅗' => '3/5', '⅘' => '4/5', '⅙' => '1/6',
        '⅚' => '5/6', '⅐' => '1/7', '⅛' => '1/8', '⅜' => '3/8', '⅝' => '5/8',
        '⅞' => '7/8', '⅑' => '1/9', '⅒' => '1/10',
    ];

    /** Canonical unit => the spellings seen in the wild. */
    private const UNITS = [
        'cup' => ['cup', 'cups', 'c'],
        'tbsp' => ['tbsp', 'tbsps', 'tablespoon', 'tablespoons', 'tbs', 'tb'],
        'tsp' => ['tsp', 'tsps', 'teaspoon', 'teaspoons'],
        'oz' => ['oz', 'ounce', 'ounces'],
        'lb' => ['lb', 'lbs', 'pound', 'pounds'],
        'g' => ['g', 'gram', 'grams'],
        'kg' => ['kg', 'kilogram', 'kilograms'],
        'ml' => ['ml', 'millilitre', 'milliliter', 'millilitres', 'milliliters'],
        'l' => ['l', 'litre', 'liter', 'litres', 'liters'],
        'clove' => ['clove', 'cloves'],
        'can' => ['can', 'cans'],
        'jar' => ['jar', 'jars'],
        'package' => ['package', 'packages', 'pkg', 'packet', 'packets'],
        'slice' => ['slice', 'slices'],
        'pinch' => ['pinch', 'pinches'],
        'dash' => ['dash', 'dashes'],
        'bunch' => ['bunch', 'bunches'],
        'stalk' => ['stalk', 'stalks'],
        'sprig' => ['sprig', 'sprigs'],
        'head' => ['head', 'heads'],
        'quart' => ['quart', 'quarts', 'qt'],
        'pint' => ['pint', 'pints', 'pt'],
    ];

    /**
     * Preparation notes that follow the ingredient. Dropped so "onion, diced"
     * and "onion, thinly sliced" become the same ingredient.
     */
    private const PREP_WORDS = [
        'chopped', 'diced', 'minced', 'sliced', 'grated', 'shredded', 'melted',
        'softened', 'divided', 'drained', 'rinsed', 'peeled', 'cubed', 'crushed',
        'beaten', 'packed', 'thawed', 'trimmed', 'halved', 'quartered', 'julienned',
        'to taste', 'optional', 'for serving', 'for garnish', 'plus more',
        'room temperature', 'at room temperature', 'finely', 'thinly', 'roughly',
        'freshly', 'cut into', 'torn', 'crumbled', 'seeded', 'stemmed', 'zested',
        // After "roughly", so that word is consumed whole rather than leaving
        // "ly" behind.
        'rough', 'coarse', 'coarsely', 'lightly', 'well',
    ];

    /**
     * Phrases where a preparation word is part of what the thing is, rather
     * than a note about what to do to it.
     *
     * "Crushed red pepper" is a jar of dried chilli flakes. Strip the word and
     * it becomes "red pepper", which is a fresh bell pepper — a different
     * aisle, a different ingredient, and one that then lands on the grocery
     * list every time a recipe asks for half a teaspoon of chilli flakes.
     */
    private const PRODUCT_NAMES = [
        'crushed red pepper',
        'crushed tomatoes',
        'crushed pineapple',
    ];

    /**
     * Containers the amount is packaged in. "15 oz cans great northern beans"
     * is beans, not cans, so these are dropped once the unit is known.
     */
    private const CONTAINER_WORDS = [
        'can', 'cans', 'box', 'boxes', 'bag', 'bags', 'jar', 'jars', 'bottle',
        'bottles', 'container', 'containers', 'package', 'packages', 'pkg',
        'packet', 'packets', 'tub', 'tubs', 'carton', 'cartons', 'block', 'blocks',
        'pk', 'pack', 'packs', 'ounce', 'ounces',
    ];

    /**
     * Measurement words that turn up with no number in front, usually because
     * the site wrote "Teaspoon* salt" or "Handful each fresh thyme".
     */
    private const BARE_MEASURES = [
        'teaspoon', 'teaspoons', 'tablespoon', 'tablespoons', 'handful',
        'handfuls', 'pinch', 'dash', 'splash', 'each', 'to taste',
        // The vaguer end of the same idea, which recipe writers reach for
        // constantly: "scrunch of pepper" was becoming an ingredient in its
        // own right rather than the pepper it plainly is.
        'scrunch', 'grind', 'grinding', 'twist', 'crack', 'sprinkle',
        'sprinkling', 'drizzle', 'glug', 'knob', 'squeeze', 'few',
    ];

    public static function parse(string $line): self
    {
        $raw = trim($line);
        $working = mb_strtolower($raw);

        // Leading punctuation left over from list markup: "/ /3-4lbs beef".
        $working = preg_replace('/^[^\p{L}\p{N}]+/u', '', $working) ?? $working;
        $working = self::stripLabel($working);
        $working = strtr($working, self::SLASHES);
        $working = strtr($working, self::FRACTIONS);
        $working = self::normaliseAmounts($working);

        // Bracketed asides are almost always metric equivalents or brand notes.
        // Applied repeatedly because they nest — "2 (6-ounce) breasts)" would
        // otherwise match "(2 (6-ounce)" and strand the closing bracket in the
        // ingredient name. Any bracket still standing after that is unbalanced
        // in the source, so it goes too.
        do {
            $before = $working;
            $working = preg_replace('/\([^()]*\)/', ' ', $working) ?? $working;
        } while ($working !== $before);

        $working = str_replace(['(', ')', '[', ']'], ' ', $working);
        $working = preg_replace('/\s+/', ' ', trim($working)) ?? $working;

        [$quantity, $working] = self::extractQuantity($working);
        [$unit, $working] = self::extractUnit($working);
        $working = self::stripContainers($working);

        $name = self::cleanName($working);

        return new self(
            raw: $raw,
            quantity: $quantity,
            unit: $unit,
            // Fall back to the original line rather than inventing a blank
            // ingredient: a wrong-looking name is far easier to fix than a
            // missing one.
            name: $name !== '' ? $name : $raw,
        );
    }

    /**
     * Strip a leading label.
     *
     * Recipe sites head their lists with "Garnish:", "Toppings:" or "For the
     * caesar salad:". A short tail after the colon is the actual ingredient; a
     * long one is prose about it, in which case the words before the colon are
     * the closest thing to a name.
     */
    private static function stripLabel(string $text): string
    {
        $colon = mb_strpos($text, ':');

        if ($colon === false) {
            return $text;
        }

        $before = trim(mb_substr($text, 0, $colon));
        $after = trim(mb_substr($text, $colon + 1));

        if ($after === '') {
            return $before;
        }

        return mb_strlen($after) <= 40 ? $after : $before;
    }

    /**
     * Pull joined amounts apart: "15oz", "450g" and "14.5-ounce" are all one
     * token as written, so neither the quantity nor the unit is found.
     * Hyphens between two numbers are left alone, since "2-3" is a range.
     */
    private static function normaliseAmounts(string $text): string
    {
        $text = preg_replace('/(\d)-(?=[a-z])/u', '$1 ', $text) ?? $text;
        $text = preg_replace('/(\d)(?=[a-z])/u', '$1 ', $text) ?? $text;

        return $text;
    }

    /**
     * Drop the packaging once the amount has been read off it.
     */
    private static function stripContainers(string $text): string
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];

        while ($words !== [] && in_array($words[0], self::CONTAINER_WORDS, true)) {
            array_shift($words);
        }

        return implode(' ', $words);
    }

    /**
     * @return array{0: float|null, 1: string}
     */
    private static function extractQuantity(string $text): array
    {
        // "2 1/2", "1/2", "2.5", "2", and ranges like "2-3" (the low end wins,
        // since buying too little is the recoverable mistake).
        $pattern = '/^(?<whole>\d+)\s+(?<num>\d+)\s*\/\s*(?<den>\d+)'
            .'|^(?<n2>\d+)\s*\/\s*(?<d2>\d+)'
            .'|^(?<dec>\d+(?:\.\d+)?)\s*(?:-|–|to)\s*\d+(?:\.\d+)?'
            .'|^(?<plain>\d+(?:\.\d+)?)/u';

        if (! preg_match($pattern, $text, $m)) {
            return [null, $text];
        }

        $quantity = match (true) {
            ($m['whole'] ?? '') !== '' => (int) $m['whole'] + (int) $m['num'] / max(1, (int) $m['den']),
            ($m['n2'] ?? '') !== '' => (int) $m['n2'] / max(1, (int) $m['d2']),
            ($m['dec'] ?? '') !== '' => (float) $m['dec'],
            default => (float) $m['plain'],
        };

        return [round($quantity, 3), trim(mb_substr($text, mb_strlen($m[0])))];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function extractUnit(string $text): array
    {
        $first = strtok($text, ' ');

        if ($first === false) {
            return [null, $text];
        }

        $candidate = rtrim($first, '.');

        foreach (self::UNITS as $canonical => $spellings) {
            if (in_array($candidate, $spellings, true)) {
                return [$canonical, trim(mb_substr($text, mb_strlen($first)))];
            }
        }

        return [null, $text];
    }

    private static function cleanName(string $text): string
    {
        // Everything after the first comma is preparation, not identity.
        $text = explode(',', $text)[0];

        // "X or Y" offers a substitution, and the first is what the recipe
        // actually calls for — but only when the left side is a complete thing.
        // In "brown or jasmine rice" the alternation is on the adjective, and
        // splitting yields "brown", so a single word before the "or" is left
        // alone. A long correct name beats a short wrong one.
        $parts = preg_split('/\s+\bor\b\s+/u', trim($text)) ?: [$text];

        if (count($parts) > 1 && str_word_count($parts[0]) >= 2) {
            $text = $parts[0];
        }

        $text = trim($text);

        // Measurement words with no number in front, left behind by lines like
        // "Teaspoon* salt" or "Handful each fresh thyme".
        $changed = true;
        while ($changed) {
            $changed = false;

            // Re-checked every pass rather than once at the start, because
            // taking a measure off exposes what followed it: "scrunch of
            // pepper" loses the scrunch and is left standing on the "of".
            $stripped = preg_replace('/^(?:of|a|an)\s+/u', '', $text) ?? $text;

            if ($stripped !== $text) {
                $text = $stripped;
                $changed = true;
            }

            foreach (self::BARE_MEASURES as $measure) {
                $pattern = '/^'.preg_quote($measure, '/').'\*?\b\s*/u';
                $stripped = preg_replace($pattern, '', $text) ?? $text;
                if ($stripped !== $text) {
                    $text = $stripped;
                    $changed = true;
                }
            }
        }

        // Held out of the way while preparation words are stripped, then put
        // back. The placeholder is plain letters and digits so nothing below
        // can match inside it.
        $held = [];

        foreach (self::PRODUCT_NAMES as $index => $phrase) {
            $token = 'PRODUCTNAME'.$index;
            $replaced = preg_replace('/\b'.preg_quote($phrase, '/').'\b/iu', $token, $text) ?? $text;

            if ($replaced !== $text) {
                $held[$token] = $phrase;
                $text = $replaced;
            }
        }

        foreach (self::PREP_WORDS as $word) {
            $text = preg_replace('/\b'.preg_quote($word, '/').'\b/', ' ', $text) ?? $text;
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B-–—.*()[]");
        $text = strtr($text, $held);

        // Not ucfirst: that works on bytes, so a name beginning with any
        // multibyte character came back with its first byte mangled and the
        // rest invalid UTF-8 — which the database then silently reduced to
        // nothing, collapsing several ingredients into one blank row.
        return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
