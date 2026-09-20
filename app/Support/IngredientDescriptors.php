<?php

namespace App\Support;

/**
 * Words that describe an ingredient without changing what it is.
 *
 * "Large eggs" are eggs. "Lean ground beef" is ground beef. "Boneless skinless
 * chicken breasts" are chicken breasts. Left alone, each becomes its own row
 * with its own pantry stock and its own grocery line, and the app ends up
 * believing the house has four kinds of butter.
 *
 * The list is deliberately short, and what is missing matters more than what
 * is here:
 *
 *  - "fresh" and "dried", which are the whole basis of the herb distinction.
 *  - "ground", because ground beef is not beef.
 *  - "whole", because whole milk is not milk and a whole chicken is not
 *    chicken.
 *  - "sweetened" and "unsweetened", which is the difference between a tin of
 *    condensed milk and a disappointing one.
 *
 * A word earns its place here only when the two names name the same thing to
 * someone standing in a shop.
 */
class IngredientDescriptors
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            // Cuts and trimming
            'boneless', 'skinless', 'bone-in', 'bone in', 'thin cut', 'thin-cut',
            'thinly sliced', 'trimmed',

            // Fat and salt
            'lean', 'extra lean', 'extra-lean', 'unsalted', 'salted', 'lightly salted',
            'low-sodium', 'low sodium', 'reduced-sodium', 'reduced sodium',
            'low-fat', 'low fat', 'reduced-fat', 'reduced fat', 'fat-free',
            'fat free', 'nonfat', 'non-fat', 'lite', 'light',

            // Size
            'large', 'extra large', 'extra-large', 'jumbo', 'medium', 'small',
            'baby', 'mini',

            // Grade and provenance, which say something about the shopping
            // rather than about the cooking
            'extra virgin', 'extra-virgin', 'virgin', 'organic', 'free-range',
            'free range', 'grass-fed', 'grass fed', 'wild caught', 'wild-caught',
            'all natural', 'all-natural', 'natural', 'premium', 'quality',
            'best quality', 'good quality',
        ];
    }

    /**
     * The name with its descriptors taken off.
     *
     * Returns the original when stripping would leave nothing — "Boneless" on
     * its own is a parsing fault, not an ingredient, and an empty name matches
     * nothing and helps nobody.
     */
    public static function strip(string $name): string
    {
        $original = trim($name);

        // Nothing to take off, so the name is returned exactly as it came.
        // Rebuilding it regardless would lowercase the lot and hand back
        // "Half & half" and "Bbq seasoning".
        if (! self::hasDescriptor($original)) {
            return $original;
        }

        $stripped = mb_strtolower($original);

        // Longest first, so "extra virgin" goes before "virgin" and takes the
        // whole phrase rather than leaving "extra" behind.
        $descriptors = self::all();
        usort($descriptors, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($descriptors as $descriptor) {
            $stripped = preg_replace(
                '/\b'.preg_quote($descriptor, '/').'\b/u',
                ' ',
                $stripped,
            ) ?? $stripped;
        }

        // Taking a word out of "medium or large shrimp" strands the "or".
        $stripped = preg_replace('/\s+/u', ' ', $stripped) ?? $stripped;
        $stripped = preg_replace('/^(?:or|and|,)\s+|\s+(?:or|and)$/u', '', trim($stripped, ' ,')) ?? $stripped;
        $stripped = trim(preg_replace('/\s+(?:or|and)\s+/u', ' ', $stripped) ?? $stripped);

        if ($stripped === '') {
            return trim($name);
        }

        return mb_strtoupper(mb_substr($stripped, 0, 1)).mb_substr($stripped, 1);
    }

    /**
     * Whether a name is nothing but descriptors — "boneless", "large" — which
     * is what the text before a comma turns out to be in lines like
     * "boneless, skinless chicken breasts".
     */
    public static function isOnlyDescriptors(string $name): bool
    {
        $name = trim($name);

        return $name !== '' && self::strip($name) === $name && self::stripsToNothing($name);
    }

    private static function hasDescriptor(string $name): bool
    {
        $lower = mb_strtolower($name);

        foreach (self::all() as $descriptor) {
            if (preg_match('/\b'.preg_quote($descriptor, '/').'\b/u', $lower)) {
                return true;
            }
        }

        return false;
    }

    private static function stripsToNothing(string $name): bool
    {
        $stripped = mb_strtolower(trim($name));

        foreach (self::all() as $descriptor) {
            $stripped = preg_replace('/\b'.preg_quote($descriptor, '/').'\b/u', ' ', $stripped) ?? $stripped;
        }

        return trim(preg_replace('/[\s,]+/u', '', $stripped) ?? $stripped) === '';
    }
}
