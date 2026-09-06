<?php

namespace App\Support;

/**
 * One jar on the spice rack.
 */
class PantryStaple
{
    /**
     * @param  list<string>  $aliases  Other ways recipes write this. Matched
     *                                 whole, never as substrings, so "garlic
     *                                 cloves" cannot be read as "cloves".
     * @param  bool  $betterFresh  Whether the fresh form is the better one when
     *                             a recipe does not say which it wants.
     * @param  bool  $onRack  False for a phrase that means several jars rather
     *                        than being one — "salt and pepper" is kept off the
     *                        grocery list like any staple, but there is no such
     *                        jar to show in the pantry.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly array $aliases = [],
        public readonly bool $betterFresh = false,
        public readonly bool $onRack = true,
    ) {}

    /** @return list<string> */
    public function allNames(): array
    {
        return array_values(array_unique(array_map(
            fn (string $n) => mb_strtolower($n),
            [$this->name, ...$this->aliases],
        )));
    }
}
