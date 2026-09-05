<?php

namespace App\Support;

use App\Enums\ProteinType;

/**
 * Works out a recipe's protein from what is actually in it.
 *
 * The source spreadsheet's "Other" sheet was a catch-all for dishes whose
 * protein was simply not stated — Burrito Skillet is built on ground beef and
 * was only filed there because it did not fit a sheet. Reading the ingredients
 * recovers what the sheet name lost.
 *
 * Only consulted for recipes with no stated protein: an explicit Chicken is a
 * fact, and a keyword list has no business overruling it.
 */
class ProteinGuesser
{
    /**
     * Ordered: the first match wins, so a dish with both beef and bacon reads
     * as beef rather than pork. Beef and chicken lead because they are the most
     * common mains rather than a garnish.
     *
     * @return list<array{ProteinType, list<string>}>
     */
    private function rules(): array
    {
        return [
            [ProteinType::Beef, [
                'ground beef', 'beef', 'steak', 'brisket', 'chuck roast', 'sirloin',
                'ribeye', 'ground chuck', 'corned beef', 'short rib',
            ]],
            [ProteinType::Chicken, [
                'chicken breast', 'chicken thigh', 'chicken', 'rotisserie chicken',
            ]],
            [ProteinType::Pork, [
                'pork', 'bacon', 'sausage', 'ham', 'chorizo', 'prosciutto',
                'pancetta', 'pepperoni', 'carnitas',
            ]],
            [ProteinType::Seafood, [
                'shrimp', 'prawn', 'salmon', 'tuna', 'cod', 'tilapia', 'halibut',
                'crab', 'lobster', 'scallop', 'ahi', 'fish fillet', 'mahi',
            ]],
            [ProteinType::Chicken, [
                'turkey',
            ]],
        ];
    }

    /**
     * @param  list<string>  $ingredientNames
     */
    public function guess(string $recipeName, array $ingredientNames = []): ProteinType
    {
        // The name is the stronger signal — "Beef Tacos" is beef even if the
        // ingredient list was never filled in.
        $name = mb_strtolower($recipeName);
        $ingredients = mb_strtolower(implode(' | ', $ingredientNames));

        foreach ([$name, $ingredients] as $haystack) {
            if ($haystack === '') {
                continue;
            }

            foreach ($this->rules() as [$protein, $keywords]) {
                foreach ($keywords as $keyword) {
                    if (str_contains($haystack, $keyword)) {
                        return $protein;
                    }
                }
            }
        }

        // Nothing meaty found. With ingredients present that is a real answer;
        // with none it is only a guess, but Vegetarian is the honest default
        // for a dish whose protein nobody ever recorded.
        return ProteinType::Vegetarian;
    }
}
