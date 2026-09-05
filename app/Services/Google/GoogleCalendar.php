<?php

namespace App\Services\Google;

use App\Models\GoogleCredential;
use App\Models\HouseholdSetting;
use App\Models\MealPlanEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Spec 4.8 — one-way push of the meal plan onto a Google calendar.
 *
 * One way by design: the app's own plan is the source of truth, so nothing is
 * read back. An event edited in Google is overwritten the next time its slot
 * changes, which is the documented behaviour rather than a bug.
 */
class GoogleCalendar
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    public function __construct(
        private readonly GoogleOAuth $oauth = new GoogleOAuth,
    ) {}

    /**
     * The writable calendars on the account, for choosing where meals go.
     *
     * @return Collection<int, array{id: string, name: string, primary: bool}>
     */
    public function calendars(GoogleCredential $credential): Collection
    {
        $response = Http::withToken($this->oauth->accessToken($credential))
            ->get(self::BASE.'/users/me/calendarList', ['minAccessRole' => 'writer', 'maxResults' => 100]);

        if (! $response->successful()) {
            throw new RuntimeException('Could not read the calendar list from Google.');
        }

        return collect($response->json('items') ?? [])
            ->map(fn (array $item) => [
                'id' => (string) ($item['id'] ?? ''),
                'name' => (string) ($item['summary'] ?? $item['id'] ?? 'Untitled'),
                'primary' => (bool) ($item['primary'] ?? false),
            ])
            ->filter(fn (array $c) => $c['id'] !== '')
            // The one most likely to be wanted first: a calendar actually
            // called "Meals", then everything else alphabetically.
            ->sortBy(fn (array $c) => [str_contains(mb_strtolower($c['name']), 'meal') ? 0 : 1, $c['name']])
            ->values();
    }

    /**
     * Create or update the event for one slot, and return its id.
     *
     * Returns null when the slot has nothing in it, having removed any event it
     * previously had — emptying a slot must clear the calendar too.
     */
    public function syncEntry(MealPlanEntry $entry): ?string
    {
        $credential = GoogleCredential::current();

        if (! $credential->isReady()) {
            return $entry->google_event_id;
        }

        $entry->loadMissing('components.recipe', 'components.simpleItem');

        if ($entry->components->isEmpty()) {
            $this->deleteEntry($entry);

            return null;
        }

        $token = $this->oauth->accessToken($credential);
        $body = $this->eventBody($entry);

        $response = $entry->google_event_id
            ? Http::withToken($token)->patch(
                self::BASE.'/calendars/'.rawurlencode($credential->calendar_id).'/events/'.rawurlencode($entry->google_event_id),
                $body,
            )
            : Http::withToken($token)->post(
                self::BASE.'/calendars/'.rawurlencode($credential->calendar_id).'/events',
                $body,
            );

        // An event deleted in Google leaves a stale id behind. Rather than
        // failing forever, drop the id and create a fresh one.
        if ($response->status() === 404 && $entry->google_event_id) {
            $entry->update(['google_event_id' => null]);

            return $this->syncEntry($entry->fresh());
        }

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? 'Google rejected the calendar update.';
            $credential->noteFailure($message);

            throw new RuntimeException($message);
        }

        $credential->noteSuccess();
        $eventId = $response->json('id');

        $entry->update(['google_event_id' => $eventId]);

        return $eventId;
    }

    public function deleteEntry(MealPlanEntry $entry): void
    {
        $credential = GoogleCredential::current();

        if (! $credential->isReady() || blank($entry->google_event_id)) {
            return;
        }

        try {
            Http::withToken($this->oauth->accessToken($credential))->delete(
                self::BASE.'/calendars/'.rawurlencode($credential->calendar_id).'/events/'.rawurlencode($entry->google_event_id),
            );
        } catch (\Throwable $e) {
            $credential->noteFailure($e->getMessage());
        }

        $entry->update(['google_event_id' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventBody(MealPlanEntry $entry): array
    {
        $timezone = HouseholdSetting::current()->timezone ?: config('app.timezone');
        [$hour, $minute] = $entry->slot->defaultTime();

        $start = Carbon::parse($entry->date->toDateString(), $timezone)->setTime($hour, $minute);
        $end = $start->copy()->addMinutes($entry->slot->durationMinutes());

        return [
            'summary' => $this->title($entry),
            'description' => $this->description($entry),
            'start' => ['dateTime' => $start->toRfc3339String(), 'timeZone' => $timezone],
            'end' => ['dateTime' => $end->toRfc3339String(), 'timeZone' => $timezone],
            'source' => ['title' => "What's For Dinner", 'url' => config('app.url')],
            // Meals are not appointments; a reminder for every one of them
            // three times a day would get the calendar muted.
            'reminders' => ['useDefault' => false],
        ];
    }

    /**
     * Spec 4.8: the primary recipe names the event; a slot with no primary is
     * summarised from whatever is in it.
     */
    private function title(MealPlanEntry $entry): string
    {
        if ($primary = $entry->primaryComponent()) {
            return $primary->displayName();
        }

        $names = $entry->components->map->displayName()->filter();

        return $names->isEmpty()
            ? $entry->slot->label()
            : $entry->slot->label().': '.$names->join(', ');
    }

    private function description(MealPlanEntry $entry): string
    {
        $lines = $entry->components
            ->sortByDesc('is_primary')
            ->map(fn ($component) => '• '.$component->displayName())
            ->all();

        $lines[] = '';
        $lines[] = "Serves {$entry->household_size_used}.";
        $lines[] = config('app.url').'/tonight?date='.$entry->date->toDateString();

        return implode("\n", $lines);
    }
}
