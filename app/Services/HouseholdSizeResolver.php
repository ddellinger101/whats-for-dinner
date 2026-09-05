<?php

namespace App\Services;

use App\Models\HouseholdSetting;
use App\Models\MealPlanEntry;
use App\Models\WeeklyHouseholdSchedule;
use Illuminate\Support\Carbon;

/**
 * Works out how many people a given day is cooking for (spec 4.3).
 *
 * The weekly schedule is only ever a default. Holidays, guests and swapped
 * weekends come up often enough that a manually chosen number always wins and
 * is never recalculated away.
 */
class HouseholdSizeResolver
{
    /** @var array<int, WeeklyHouseholdSchedule>|null */
    private ?array $schedule = null;

    /**
     * The scheduled size for a date, ignoring any per-slot override.
     */
    public function scheduledFor(Carbon $date): int
    {
        $settings = HouseholdSetting::current();
        $row = $this->schedule()[$date->dayOfWeek] ?? null;

        // No row configured for this weekday: fall back to the smaller household
        // rather than over-buying groceries.
        return $row?->servingsFor((bool) $settings->kids_this_weekend) ?? 2;
    }

    /**
     * The size actually in effect for a slot: a manual choice if one was made,
     * otherwise the schedule.
     */
    public function forEntry(MealPlanEntry $entry): int
    {
        if ($entry->servings_manually_set) {
            return $entry->household_size_used;
        }

        return $this->scheduledFor($entry->date);
    }

    /**
     * Re-apply the schedule to a slot. Deliberately a no-op on a slot whose
     * size was set by hand — that is the whole point of the flag.
     */
    public function refresh(MealPlanEntry $entry): MealPlanEntry
    {
        if ($entry->servings_manually_set) {
            return $entry;
        }

        $entry->update(['household_size_used' => $this->scheduledFor($entry->date)]);

        return $entry;
    }

    /** @return array<int, WeeklyHouseholdSchedule> */
    private function schedule(): array
    {
        return $this->schedule ??= WeeklyHouseholdSchedule::all()
            ->keyBy('day_of_week')
            ->all();
    }
}
