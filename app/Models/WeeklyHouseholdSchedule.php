<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeeklyHouseholdSchedule extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'day_of_week', 'servings_with_kids', 'servings_without_kids', 'varies_by_custody',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'servings_with_kids' => 'integer',
            'servings_without_kids' => 'integer',
            'varies_by_custody' => 'boolean',
        ];
    }

    /**
     * Weekdays are fixed; only custody-variable days consult the
     * "kids this weekend" toggle (spec 3, WeeklyHouseholdSchedule).
     */
    public function servingsFor(bool $kidsThisWeekend): int
    {
        if (! $this->varies_by_custody) {
            return $this->servings_with_kids;
        }

        return $kidsThisWeekend ? $this->servings_with_kids : $this->servings_without_kids;
    }
}
