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
    ];

    public static function parse(string $line): self
    {
        $raw = trim($line);
        $working = mb_strtolower($raw);

        $working = strtr($working, self::FRACTIONS);

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

        $text = preg_replace('/^(of|a|an)\s+/', '', trim($text)) ?? $text;

        foreach (self::PREP_WORDS as $word) {
            $text = preg_replace('/\b'.preg_quote($word, '/').'\b/', ' ', $text) ?? $text;
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B-–—.*()[]");

        return ucfirst($text);
    }
}
