<?php

namespace App\Http\Controllers;

use App\Models\GoogleCredential;
use App\Services\Google\GoogleCalendar;
use App\Services\Google\GoogleOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Spec 4.8's connection flow. One household, one Google account: the calendar
 * is shared in Google itself, so a second authorisation would only mean two
 * apps writing the same events.
 */
class GoogleController extends Controller
{
    public function __construct(
        private readonly GoogleOAuth $oauth = new GoogleOAuth,
        private readonly GoogleCalendar $calendar = new GoogleCalendar,
    ) {}

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->oauth->isConfigured()) {
            return redirect()->route('settings')
                ->withErrors(['google' => 'Google credentials are not configured on this server.']);
        }

        // Guards the callback against being replayed or forged: only a redirect
        // carrying the state this session just issued is accepted.
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($this->oauth->authorisationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('google_oauth_state');

        if (blank($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('settings')
                ->withErrors(['google' => 'That connection attempt did not match this session. Please try again.']);
        }

        if ($error = $request->query('error')) {
            return redirect()->route('settings')->withErrors([
                'google' => $error === 'access_denied'
                    ? 'The connection was cancelled.'
                    : "Google returned an error: {$error}",
            ]);
        }

        $code = (string) $request->query('code');

        if (blank($code)) {
            return redirect()->route('settings')->withErrors(['google' => 'Google did not return an authorisation code.']);
        }

        try {
            $credential = $this->oauth->exchangeCode($code);
        } catch (Throwable $e) {
            return redirect()->route('settings')->withErrors(['google' => $e->getMessage()]);
        }

        // Nothing can be written until a calendar is chosen, so say so rather
        // than reporting success on a connection that cannot do anything yet.
        return redirect()->route('settings')->with(
            'status',
            $credential->calendar_id
                ? 'Google reconnected.'
                : 'Connected to Google. Now choose which calendar meals should go on.',
        );
    }

    public function selectCalendar(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'calendar_id' => ['required', 'string', 'max:255'],
            'calendar_name' => ['nullable', 'string', 'max:255'],
        ]);

        $credential = GoogleCredential::current();

        if (! $credential->isConnected()) {
            return redirect()->route('settings')->withErrors(['google' => 'Connect to Google first.']);
        }

        $credential->update([
            'calendar_id' => $validated['calendar_id'],
            'calendar_name' => $validated['calendar_name'] ?? null,
            'last_error' => null,
        ]);

        return redirect()->route('settings')
            ->with('status', 'Meals will be added to '.($validated['calendar_name'] ?? 'the chosen calendar').'.');
    }

    public function disconnect(): RedirectResponse
    {
        $this->oauth->disconnect(GoogleCredential::current());

        return redirect()->route('settings')->with('status', 'Disconnected from Google.');
    }

    /**
     * Push the whole of the current week at once, for a calendar connected
     * after the plan was already made.
     */
    public function backfill(Request $request): RedirectResponse
    {
        $credential = GoogleCredential::current();

        if (! $credential->isReady()) {
            return redirect()->route('settings')->withErrors(['google' => 'Connect and choose a calendar first.']);
        }

        $start = now()->startOfWeek(\Illuminate\Support\Carbon::SUNDAY);

        $entries = \App\Models\MealPlanEntry::query()
            ->with('components')
            ->whereBetween('date', [$start->toDateString(), $start->copy()->addDays(6)->toDateString()])
            ->get()
            ->filter(fn ($entry) => $entry->components->isNotEmpty());

        foreach ($entries as $entry) {
            \App\Jobs\SyncMealToCalendar::dispatch($entry->id);
        }

        return redirect()->route('settings')->with(
            'status',
            "Sending {$entries->count()} ".str('meal')->plural($entries->count()).' to your calendar.',
        );
    }
}
