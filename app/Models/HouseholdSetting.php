<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class HouseholdSetting extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'kids_this_weekend', 'shopping_day_of_week', 'diet_mode', 'google_calendar_id',
    ];

    protected function casts(): array
    {
        return [
            'kids_this_weekend' => 'boolean',
            'shopping_day_of_week' => 'integer',
        ];
    }

    /**
     * Single-row table; every caller goes through here.
     */
    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    /**
     * Spec 4.1: use-by windows date from the upcoming shopping day. Today counts
     * as upcoming, so planning on a Sunday buys that same Sunday.
     */
    public function upcomingShoppingDate(?Carbon $from = null): Carbon
    {
        $from = ($from ?? Carbon::today())->copy()->startOfDay();
        $delta = ($this->shopping_day_of_week - $from->dayOfWeek + 7) % 7;

        return $from->addDays($delta);
    }

    public function dietModeActive(): bool
    {
        return filled($this->diet_mode);
    }
}
