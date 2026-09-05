<?php

namespace Database\Seeders;

use App\Models\HouseholdSetting;
use App\Models\WeeklyHouseholdSchedule;
use Illuminate\Database\Seeder;

class HouseholdSeeder extends Seeder
{
    /**
     * Spec 3: "Mon/Tue = 2, Wed/Thu = 5, alternating weekends = 5, other weekend
     * days = 2 (editable toggle for 'kids this weekend')".
     *
     * Friday is not specified in the spec. Treated here as custody-variable on
     * the assumption that a custody weekend starts Friday evening — one row edit
     * to change if that is wrong.
     */
    public function run(): void
    {
        $days = [
            // day_of_week => [with kids, without kids, varies by custody]
            0 => [5, 2, true],   // Sunday
            1 => [2, 2, false],  // Monday
            2 => [2, 2, false],  // Tuesday
            3 => [5, 5, false],  // Wednesday
            4 => [5, 5, false],  // Thursday
            5 => [5, 2, true],   // Friday — assumption, see above
            6 => [5, 2, true],   // Saturday
        ];

        foreach ($days as $dayOfWeek => [$withKids, $withoutKids, $varies]) {
            WeeklyHouseholdSchedule::updateOrCreate(
                ['day_of_week' => $dayOfWeek],
                [
                    'servings_with_kids' => $withKids,
                    'servings_without_kids' => $withoutKids,
                    'varies_by_custody' => $varies,
                ],
            );
        }

        // Sunday shopping day, matching the Sunday planning session in spec 1.
        HouseholdSetting::current()->update([
            'shopping_day_of_week' => 0,
        ]);
    }
}
