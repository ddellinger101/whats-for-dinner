<?php

namespace Tests\Feature;

use App\Enums\MealSlot;
use App\Models\HouseholdSetting;
use App\Models\MealPlanEntry;
use App\Services\HouseholdSizeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HouseholdServingsTest extends TestCase
{
    use RefreshDatabase;

    private HouseholdSizeResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->resolver = new HouseholdSizeResolver;
    }

    /** Weekdays are fixed regardless of whose weekend it is. */
    public function test_weekday_servings_ignore_the_custody_toggle(): void
    {
        HouseholdSetting::current()->update(['kids_this_weekend' => true]);
        // Monday 2026-09-07, Wednesday 2026-09-09.
        $this->assertSame(2, $this->resolver->scheduledFor(Carbon::parse('2026-09-07')));
        $this->assertSame(5, $this->resolver->scheduledFor(Carbon::parse('2026-09-09')));

        HouseholdSetting::current()->update(['kids_this_weekend' => false]);
        $this->assertSame(2, (new HouseholdSizeResolver)->scheduledFor(Carbon::parse('2026-09-07')));
        $this->assertSame(5, (new HouseholdSizeResolver)->scheduledFor(Carbon::parse('2026-09-09')));
    }

    /** Kids are here every other Fri/Sat/Sun, so all three follow the toggle. */
    public function test_friday_saturday_and_sunday_follow_the_custody_toggle(): void
    {
        $friday = Carbon::parse('2026-09-11');
        $saturday = Carbon::parse('2026-09-12');
        $sunday = Carbon::parse('2026-09-13');

        HouseholdSetting::current()->update(['kids_this_weekend' => true]);
        $withKids = new HouseholdSizeResolver;
        $this->assertSame(5, $withKids->scheduledFor($friday));
        $this->assertSame(5, $withKids->scheduledFor($saturday));
        $this->assertSame(5, $withKids->scheduledFor($sunday));

        HouseholdSetting::current()->update(['kids_this_weekend' => false]);
        $without = new HouseholdSizeResolver;
        $this->assertSame(2, $without->scheduledFor($friday));
        $this->assertSame(2, $without->scheduledFor($saturday));
        $this->assertSame(2, $without->scheduledFor($sunday));
    }

    /** A number chosen by hand must survive recalculation — holidays, guests. */
    public function test_manual_servings_are_never_recalculated_away(): void
    {
        $entry = MealPlanEntry::create([
            'date' => '2026-09-07',   // Monday, scheduled 2
            'slot' => MealSlot::Dinner,
            'household_size_used' => 2,
        ]);

        // Thanksgiving-style: twelve people on a day the schedule says 2.
        $entry->setServings(12);

        $this->assertTrue($entry->fresh()->servings_manually_set);
        $this->assertSame(12, $this->resolver->forEntry($entry->fresh()));

        $this->resolver->refresh($entry->fresh());
        $this->assertSame(12, $entry->fresh()->household_size_used);
    }

    public function test_a_slot_can_be_handed_back_to_the_schedule(): void
    {
        $entry = MealPlanEntry::create([
            'date' => '2026-09-07',
            'slot' => MealSlot::Dinner,
            'household_size_used' => 2,
        ]);

        $entry->setServings(9);
        $this->assertSame(9, $this->resolver->forEntry($entry->fresh()));

        $entry->useScheduledServings();
        $this->assertFalse($entry->fresh()->servings_manually_set);
        $this->assertSame(2, $this->resolver->forEntry($entry->fresh()));

        $this->resolver->refresh($entry->fresh());
        $this->assertSame(2, $entry->fresh()->household_size_used);
    }

    /** An unpinned slot tracks the schedule when the week is recalculated. */
    public function test_unpinned_slot_follows_the_schedule(): void
    {
        $entry = MealPlanEntry::create([
            'date' => '2026-09-12',   // Saturday
            'slot' => MealSlot::Dinner,
            'household_size_used' => 2,
        ]);

        HouseholdSetting::current()->update(['kids_this_weekend' => true]);
        (new HouseholdSizeResolver)->refresh($entry);

        $this->assertSame(5, $entry->fresh()->household_size_used);
    }

    public function test_servings_cannot_be_pinned_below_one(): void
    {
        $entry = MealPlanEntry::create([
            'date' => '2026-09-07',
            'slot' => MealSlot::Lunch,
            'household_size_used' => 2,
        ]);

        $entry->setServings(0);
        $this->assertSame(1, $entry->fresh()->household_size_used);
    }
}
