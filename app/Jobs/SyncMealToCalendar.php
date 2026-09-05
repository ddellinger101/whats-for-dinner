<?php

namespace App\Jobs;

use App\Models\MealPlanEntry;
use App\Services\Google\GoogleCalendar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Spec 4.8 — pushes one slot onto the calendar.
 *
 * Queued because Google is a third party on the far side of a network: picking
 * dinner must not wait on it, and must not fail because of it.
 */
class SyncMealToCalendar implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** Backoff, so a transient Google outage is not hammered. */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $entryId) {}

    /**
     * Two people editing the same slot would otherwise race and could create
     * two events for one meal.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->entryId))->releaseAfter(15)];
    }

    public function handle(GoogleCalendar $calendar): void
    {
        $entry = MealPlanEntry::with('components.recipe', 'components.simpleItem')->find($this->entryId);

        if (! $entry) {
            return;
        }

        $calendar->syncEntry($entry);
    }

    /**
     * The failure is already recorded on the credential by the service, which
     * is what the settings screen reads. This exists so a dead calendar cannot
     * take a queue worker down with it.
     */
    public function failed(?Throwable $e): void
    {
        report($e);
    }
}
