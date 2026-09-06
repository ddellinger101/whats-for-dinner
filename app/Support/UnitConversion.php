<?php

namespace App\Support;

/**
 * Adds cooking amounts up across units that mean the same kind of thing.
 *
 * Six tablespoons of olive oil in one recipe and half a cup in another are
 * fourteen tablespoons of olive oil, and leaving them as two lines on the
 * list makes the shopper do arithmetic in an aisle. These conversions are
 * exact, so there is nothing to approximate.
 *
 * Deliberately narrow. Only volume and weight convert; a clove, a can and a
 * bunch are units of different things and no amount of arithmetic turns one
 * into another, so those only ever combine with themselves.
 */
class UnitConversion
{
    /** Teaspoons, the smallest volume the parser produces. */
    private const VOLUME = [
        'tsp' => 1.0,
        'tbsp' => 3.0,
        'ml' => 0.202884,
        'fl oz' => 6.0,
        'cup' => 48.0,
        'pint' => 96.0,
        'quart' => 192.0,
        'l' => 202.884,
        'gallon' => 768.0,
    ];

    /** Grams. */
    private const WEIGHT = [
        'g' => 1.0,
        'oz' => 28.3495,
        'lb' => 453.592,
        'kg' => 1000.0,
    ];

    public static function family(?string $unit): ?string
    {
        $unit = self::normalise($unit);

        return match (true) {
            $unit === null => null,
            isset(self::VOLUME[$unit]) => 'volume',
            isset(self::WEIGHT[$unit]) => 'weight',
            default => null,
        };
    }

    /**
     * Whether two amounts can be added at all.
     *
     * Identical units always can, including the ones with no conversion — two
     * cloves are two cloves. Anything with no unit combines with anything else
     * with no unit.
     */
    public static function compatible(?string $a, ?string $b): bool
    {
        $a = self::normalise($a);
        $b = self::normalise($b);

        if ($a === $b) {
            return true;
        }

        $family = self::family($a);

        return $family !== null && $family === self::family($b);
    }

    /**
     * Add up amounts given as [quantity, unit] pairs.
     *
     * Returns the total and the unit to say it in, or a null total when any
     * amount is unknown — an unknown does not add up to a number, and
     * pretending otherwise understates the line.
     *
     * @param  list<array{float|null, string|null}>  $amounts
     * @return array{float|null, string|null}
     */
    public static function sum(array $amounts): array
    {
        if ($amounts === []) {
            return [null, null];
        }

        $units = array_map(fn (array $a) => self::normalise($a[1]), $amounts);
        $unit = $units[0];

        foreach ($units as $other) {
            if (! self::compatible($unit, $other)) {
                return [null, $unit];
            }
        }

        if (in_array(null, array_column($amounts, 0), true)) {
            return [null, self::best($amounts)];
        }

        $family = self::family($unit);

        // Nothing to convert: every amount is already in the same unit.
        if ($family === null) {
            return [round(array_sum(array_column($amounts, 0)), 3), $unit];
        }

        $target = self::best($amounts);
        $total = 0.0;

        foreach ($amounts as [$quantity, $from]) {
            $total += self::convert((float) $quantity, self::normalise($from), $target) ?? 0.0;
        }

        return [round($total, 3), $target];
    }

    public static function convert(float $quantity, ?string $from, ?string $to): ?float
    {
        $from = self::normalise($from);
        $to = self::normalise($to);

        if ($from === $to) {
            return $quantity;
        }

        $family = self::family($from);

        if ($family === null || $family !== self::family($to)) {
            return null;
        }

        $scale = $family === 'volume' ? self::VOLUME : self::WEIGHT;

        return $quantity * $scale[$from] / $scale[$to];
    }

    /**
     * The unit to state a combined amount in.
     *
     * The larger unit is used only when the total divides into it cleanly.
     * Forty-eight tablespoons are better read as three cups; eighteen are not
     * better read as 1.125 cups, and a shopper holding a bottle wants a figure
     * they can act on rather than the tidiest-looking one.
     *
     * @param  list<array{float|null, string|null}>  $amounts
     */
    private static function best(array $amounts): ?string
    {
        $units = array_values(array_filter(array_map(fn (array $a) => self::normalise($a[1]), $amounts)));

        if ($units === []) {
            return null;
        }

        $family = self::family($units[0]);

        if ($family === null) {
            return $units[0];
        }

        $scale = $family === 'volume' ? self::VOLUME : self::WEIGHT;

        // Smallest unit present, so the total can be worked out exactly before
        // deciding how to say it.
        $smallest = $units[0];
        foreach ($units as $unit) {
            if ($scale[$unit] < $scale[$smallest]) {
                $smallest = $unit;
            }
        }

        if (in_array(null, array_column($amounts, 0), true)) {
            return $smallest;
        }

        $total = 0.0;
        foreach ($amounts as [$quantity, $from]) {
            $total += (float) $quantity * $scale[self::normalise($from)] / $scale[$smallest];
        }

        // Step up only through units actually in play, so a recipe written in
        // tablespoons is not answered in gallons.
        $best = $smallest;

        foreach ($units as $unit) {
            if ($scale[$unit] <= $scale[$best]) {
                continue;
            }

            $converted = $total * $scale[$smallest] / $scale[$unit];

            // Whole units or halves. Anything else reads worse than the
            // smaller unit it came from.
            if ($converted >= 1 && abs($converted * 2 - round($converted * 2)) < 0.001) {
                $best = $unit;
            }
        }

        return $best;
    }

    private static function normalise(?string $unit): ?string
    {
        $unit = trim(mb_strtolower((string) $unit));

        return $unit === '' ? null : $unit;
    }
}
