<?php

namespace App\Support;

/**
 * What counts as coming off the bar rather than out of the cupboard.
 *
 * Defined once because two tables need it and they must not drift apart: the
 * category guesser decides that a bottle is bar stock, and the aisle guesser
 * has to reach the same answer before its own drinks and pantry rules see the
 * name — otherwise club soda is filed by the soda and simple syrup by the
 * syrup, and the two screens disagree about the same bottle.
 *
 * Deliberately absent: "cocktail" on its own, which would take cocktail sauce
 * and shrimp cocktail with it, and "sherry", which would take sherry vinegar.
 */
class BarKeywords
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            // Spirits
            'bourbon', 'whiskey', 'whisky', 'vodka', 'gin', 'tequila', 'rum',
            'mezcal', 'brandy', 'cognac', 'absinthe', 'aquavit', 'pisco',

            // Liqueurs. "Amaro" does not catch amaretto — the word ends
            // differently, and whole-word matching is the point.
            'liqueur', 'amaro', 'amaretto', 'triple sec', 'cointreau',
            'grand marnier', 'curacao', 'campari', 'aperol', 'kahlua',
            'baileys', 'irish cream', 'limoncello', 'sambuca', 'frangelico',
            'chambord', 'st germain', 'midori', 'schnapps', 'creme de',
            'jagermeister', 'fireball', 'aperitif', 'vermouth',

            // Mixers and garnishes
            'bitters', 'grenadine', 'simple syrup', 'tonic water', 'club soda',
            'sweet and sour mix', 'margarita mix', 'cocktail cherries',
            'cocktail onions', 'cocktail garnish', 'maraschino',
        ];
    }
}
