<?php

namespace App\Support;

/**
 * Reduces a branded bottle to the spirit a recipe actually means.
 *
 * Cocktail recipes name the bottle the author happened to own — "Colonel
 * E.H. Taylor Small Batch Bourbon" — and every one of those becomes its own
 * ingredient, so a bar with one bottle of bourbon in it never matches the
 * three recipes that call for bourbon under three different names.
 *
 * The rule is that the spirit is the head noun: it has to be the last thing in
 * the name. That is what separates "Tito's Handmade Vodka", which is vodka,
 * from "rum cake" and "vanilla extract in rum", which are not. Everything
 * before the head noun is dropped unless it is a style that changes what you
 * would buy — dark rum and coconut rum are different bottles, Bulleit and
 * Maker's Mark are not.
 */
class SpiritName
{
    /**
     * Styles worth keeping, because they send you to a different bottle.
     *
     * @var list<string>
     */
    private const STYLES = [
        'dark', 'light', 'white', 'gold', 'golden', 'spiced', 'coconut',
        'citrus', 'silver', 'blanco', 'reposado', 'anejo', 'añejo', 'rye',
        'aged', 'overproof', 'navy', 'dry', 'sweet', 'blended', 'single malt',
        'irish', 'scotch', 'japanese', 'canadian', 'london dry',
    ];

    /**
     * The head nouns worth reducing to. Mixers and garnishes are deliberately
     * absent — nobody writes a brand of bitters expecting a generic.
     *
     * @var list<string>
     */
    private const SPIRITS = [
        'bourbon', 'whiskey', 'whisky', 'vodka', 'gin', 'tequila', 'rum',
        'mezcal', 'brandy', 'cognac', 'absinthe', 'aquavit', 'pisco',
        'vermouth', 'amaretto', 'triple sec', 'curacao', 'schnapps',
        'limoncello', 'sambuca', 'liqueur',
    ];

    /**
     * The generic name, or null when there is nothing to reduce — including
     * when the name is already generic, so a caller can tell the difference
     * between "leave this alone" and "this was already right".
     */
    public static function generic(string $name): ?string
    {
        $normalised = self::normalise($name);

        if ($normalised === '') {
            return null;
        }

        $spirit = self::headSpirit($normalised);

        if ($spirit === null) {
            return null;
        }

        $styles = array_values(array_filter(
            self::STYLES,
            fn (string $style) => KeywordMatch::matches($normalised, $style),
        ));

        $generic = trim(implode(' ', [...$styles, $spirit]));
        $generic = mb_strtoupper(mb_substr($generic, 0, 1)).mb_substr($generic, 1);

        // Already generic, so there is nothing to say.
        return mb_strtolower($generic) === $normalised ? null : $generic;
    }

    /**
     * The spirit, but only where it ends the name.
     *
     * "Rum cake" and "rum extract" both contain rum and are neither of them a
     * bottle of rum, which is the whole reason for the rule.
     */
    private static function headSpirit(string $name): ?string
    {
        foreach (self::SPIRITS as $spirit) {
            if (str_ends_with($name, ' '.$spirit) || $name === $spirit) {
                return $spirit;
            }
        }

        return null;
    }

    private static function normalise(string $name): string
    {
        $name = mb_strtolower(trim($name));
        // Possessives and full stops in initials — "Tito's", "E.H." — are
        // noise either way, and dropping them keeps the style match simple.
        $name = str_replace(["'", '’', '.'], ['', '', ' '], $name);

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }
}
