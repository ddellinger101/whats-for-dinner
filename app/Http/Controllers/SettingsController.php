<?php

namespace App\Http\Controllers;

use App\Models\GoogleCredential;
use App\Models\HouseholdSetting;
use App\Models\WeeklyHouseholdSchedule;
use App\Services\Google\GoogleCalendar;
use App\Services\Google\GoogleOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Household settings, including the Google connection.
 *
 * The toggles here were previously only reachable from the database: the diet
 * filter, the custody switch, the shopping day and the weekly servings all
 * drive real behaviour, so leaving them unreachable made the app less
 * adjustable than the spec assumed.
 */
class SettingsController extends Controller
{
    public function index(GoogleOAuth $oauth, GoogleCalendar $calendar): View
    {
        $credential = GoogleCredential::current();
        $calendars = collect();
        $calendarError = null;

        if ($credential->isConnected()) {
            try {
                $calendars = $calendar->calendars($credential);
            } catch (Throwable $e) {
                // Shown in the page rather than thrown: a broken connection is
                // exactly the state this screen exists to make visible.
                $calendarError = $e->getMessage();
            }
        }

        return view('settings.index', [
            'settings' => HouseholdSetting::current(),
            'schedule' => WeeklyHouseholdSchedule::orderBy('day_of_week')->get(),
            'credential' => $credential,
            'calendars' => $calendars,
            'calendarError' => $calendarError,
            'googleConfigured' => $oauth->isConfigured(),
            'timezones' => \DateTimeZone::listIdentifiers(\DateTimeZone::AMERICA),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kids_this_weekend' => ['nullable', 'boolean'],
            'shopping_day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'diet_mode' => ['nullable', 'string', 'in:keto'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        HouseholdSetting::current()->update([
            'kids_this_weekend' => (bool) ($validated['kids_this_weekend'] ?? false),
            'shopping_day_of_week' => $validated['shopping_day_of_week'],
            'diet_mode' => $validated['diet_mode'] ?: null,
            'timezone' => $validated['timezone'],
        ]);

        return back()->with('status', 'Settings saved.');
    }

    /**
     * Servings per weekday, which the spec 3 defaults only approximate.
     */
    public function updateSchedule(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'array'],
            'days.*.servings_with_kids' => ['required', 'integer', 'min:1', 'max:60'],
            'days.*.servings_without_kids' => ['required', 'integer', 'min:1', 'max:60'],
            'days.*.varies_by_custody' => ['nullable', 'boolean'],
        ]);

        foreach ($validated['days'] as $dayOfWeek => $values) {
            WeeklyHouseholdSchedule::where('day_of_week', (int) $dayOfWeek)->update([
                'servings_with_kids' => $values['servings_with_kids'],
                'servings_without_kids' => $values['servings_without_kids'],
                'varies_by_custody' => (bool) ($values['varies_by_custody'] ?? false),
            ]);
        }

        return back()->with('status', 'Weekly servings saved.');
    }
}
