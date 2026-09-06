<?php

namespace App\Support;

/**
 * Does this keyword appear in this name, as a word rather than as letters?
 *
 * Shared by every keyword table in the app, because it was defined once and
 * missing everywhere else. The category guesser matched on word boundaries;
 * the aisle guesser used a plain substring test, and so filed extra virgin
 * olive oil under Drinks — "gin" is inside "virgin". It also sent kale to
 * Drinks by way of "ale", drumsticks by way of "rum", and graham crackers to
 * the meat counter by way of "ham".
 *
 * Suffix a keyword with * to match a prefix deliberately, for stems with no
 * regular plural: "anchov*" catches both anchovy and anchovies.
 */
class KeywordMatch
{
    public static function matches(string $name, string $keyword): bool
    {
        if (str_ends_with($keyword, '*')) {
            return (bool) preg_match('/\b'.preg_quote(rtrim($keyword, '*'), '/').'/u', $name);
        }

        // A trailing plural is allowed, or anchoring both ends would quietly
        // break most of the tables: "carrot" would stop matching "carrots".
        return (bool) preg_match('/\b'.preg_quote($keyword, '/').'(?:s|es)?\b/u', $name);
    }

    /**
     * @param  list<string>  $keywords
     */
    public static function any(string $name, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (self::matches($name, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
