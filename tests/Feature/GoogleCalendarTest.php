<?php

namespace Tests\Feature;

use App\Enums\MealSlot;
use App\Jobs\SyncMealToCalendar;
use App\Models\GoogleCredential;
use App\Models\HouseholdSetting;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\SimpleItem;
use App\Models\User;
use App\Services\Google\GoogleCalendar;
use App\Services\Google\GoogleOAuth;
use App\Services\MealPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec 4.8 — the one-way push of the meal plan onto Google Calendar.
 */
class GoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const WEDNESDAY = '2026-09-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->user = User::factory()->create();

        config()->set('services.google.client_id', 'test-client-id');
        config()->set('services.google.client_secret', 'test-secret');
        config()->set('services.google.redirect', 'https://chef.test/auth/google/callback');
    }

    private function connected(?string $calendarId = 'meals@group.calendar.google.com'): GoogleCredential
    {
        return tap(GoogleCredential::current())->update([
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'calendar_id' => $calendarId,
            'calendar_name' => 'Meals',
            'google_email' => 'cook@example.com',
        ]);
    }

    private function plannedDinner(string $name = 'Beef Tacos'): MealPlanEntry
    {
        // The planner dispatches its own sync; faked here so that the tests
        // below assert only on the call they make themselves.
        Queue::fake();

        $recipe = Recipe::create(['name' => $name, 'base_servings' => 4]);
        (new MealPlanner)->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        return MealPlanEntry::firstOrFail();
    }

    // ------------------------------------------------------------- connecting

    public function test_the_authorisation_url_asks_for_offline_access(): void
    {
        $url = (new GoogleOAuth)->authorisationUrl('state-123');

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        // Without prompt=consent Google withholds the refresh token on a
        // re-authorisation, leaving a connection that cannot renew itself.
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('calendar.events', $url);
        $this->assertStringContainsString('state=state-123', $url);
    }

    /** The callback must not accept a redirect this session did not start. */
    public function test_the_callback_rejects_a_mismatched_state(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'the-real-state'])
            ->get(route('google.callback', ['code' => 'abc', 'state' => 'a-forgery']))
            ->assertRedirect(route('settings'))
            ->assertSessionHasErrors('google');

        Http::assertNothingSent();
        $this->assertFalse(GoogleCredential::current()->isConnected());
    }

    public function test_a_successful_callback_stores_the_tokens(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access', 'refresh_token' => 'new-refresh',
                'expires_in' => 3599, 'scope' => 'calendar.events',
            ]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'cook@example.com']),
        ]);

        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 'state-123'])
            ->get(route('google.callback', ['code' => 'auth-code', 'state' => 'state-123']))
            ->assertRedirect(route('settings'));

        $credential = GoogleCredential::current();
        $this->assertTrue($credential->isConnected());
        $this->assertSame('cook@example.com', $credential->google_email);
        // Not usable until a calendar is chosen.
        $this->assertFalse($credential->isReady());
    }

    /** Google only returns a refresh token on first consent. */
    public function test_reconnecting_keeps_the_existing_refresh_token(): void
    {
        $this->connected();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh', 'expires_in' => 3599]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'cook@example.com']),
        ]);

        $this->actingAs($this->user)
            ->withSession(['google_oauth_state' => 's'])
            ->get(route('google.callback', ['code' => 'c', 'state' => 's']));

        $this->assertSame('refresh-token', GoogleCredential::current()->refresh_token);
    }

    /** Tokens must not be readable from a database dump. */
    public function test_tokens_are_encrypted_at_rest(): void
    {
        $this->connected();

        $raw = \Illuminate\Support\Facades\DB::table('google_credentials')->first();

        $this->assertNotSame('refresh-token', $raw->refresh_token);
        $this->assertSame('refresh-token', GoogleCredential::current()->refresh_token);
    }

    /**
     * The failure that killed this household's previous app: a consent screen
     * left in Testing has its refresh tokens revoked after seven days.
     */
    public function test_an_invalid_grant_is_explained_rather_than_swallowed(): void
    {
        $credential = $this->connected();
        $credential->update(['expires_at' => now()->subHour()]);

        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            (new GoogleOAuth)->refresh($credential);
            $this->fail('Expected the refresh to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Production', $e->getMessage());
        }

        // Recorded on the record the settings screen reads, not just logged.
        $this->assertStringContainsString('Production', GoogleCredential::current()->last_error);
    }

    // --------------------------------------------------------------- pushing

    public function test_planning_a_meal_queues_a_calendar_push(): void
    {
        $this->connected();
        Queue::fake();

        $this->plannedDinner();

        Queue::assertPushed(SyncMealToCalendar::class);
    }

    /** Nothing should be queued while there is nowhere to send it. */
    public function test_nothing_is_queued_when_google_is_not_connected(): void
    {
        Queue::fake();

        $this->plannedDinner();

        Queue::assertNotPushed(SyncMealToCalendar::class);
    }

    public function test_it_creates_an_event_titled_after_the_main_dish(): void
    {
        $this->connected();
        HouseholdSetting::current()->update(['timezone' => 'America/New_York']);

        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt-1'])]);

        $entry = $this->plannedDinner('Beef Tacos');
        (new GoogleCalendar)->syncEntry($entry->fresh());

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/events')
                && $body['summary'] === 'Beef Tacos'
                && $body['start']['timeZone'] === 'America/New_York'
                // Dinner lands at six in the household's own timezone.
                && str_contains($body['start']['dateTime'], '2026-09-09T18:00:00');
        });

        $this->assertSame('evt-1', $entry->fresh()->google_event_id);
    }

    /** Spec 4.8: a slot with no main is summarised from what is in it. */
    public function test_a_slot_without_a_main_is_summarised(): void
    {
        $this->connected();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt-2'])]);

        $item = SimpleItem::create(['name' => 'Turkey Sandwich', 'breakdown_prompted' => true]);
        (new MealPlanner)->addSide(Carbon::parse(self::WEDNESDAY), MealSlot::Lunch, $item);

        (new GoogleCalendar)->syncEntry(MealPlanEntry::firstOrFail());

        Http::assertSent(fn ($request) => ($request->data()['summary'] ?? '') === 'Lunch: Turkey Sandwich');
    }

    /** A changed meal updates its event rather than adding a second. */
    public function test_an_existing_event_is_updated_not_duplicated(): void
    {
        $this->connected();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt-1'])]);

        $entry = $this->plannedDinner();
        $entry->update(['google_event_id' => 'evt-1']);

        (new GoogleCalendar)->syncEntry($entry->fresh());

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), 'evt-1'));
    }

    /** An event deleted in Google must not wedge the sync forever. */
    public function test_a_missing_event_is_recreated(): void
    {
        $this->connected();

        Http::fakeSequence()
            ->push(['error' => ['message' => 'Not Found']], 404)
            ->push(['id' => 'evt-new'], 200);

        $entry = $this->plannedDinner();
        $entry->update(['google_event_id' => 'evt-gone']);

        (new GoogleCalendar)->syncEntry($entry->fresh());

        $this->assertSame('evt-new', $entry->fresh()->google_event_id);
    }

    /** Emptying a slot has to clear the calendar, not leave a ghost meal. */
    public function test_clearing_a_slot_deletes_its_event(): void
    {
        $this->connected();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response([], 204)]);

        $entry = $this->plannedDinner();
        $entry->update(['google_event_id' => 'evt-1']);
        $entry->components()->delete();

        (new GoogleCalendar)->syncEntry($entry->fresh());

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertNull($entry->fresh()->google_event_id);
    }

    public function test_nothing_is_sent_before_a_calendar_is_chosen(): void
    {
        $this->connected(calendarId: null);
        Http::fake();

        (new GoogleCalendar)->syncEntry($this->plannedDinner());

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------- settings

    public function test_the_settings_screen_offers_a_connect_button(): void
    {
        $this->actingAs($this->user)->get(route('settings'))
            ->assertOk()
            ->assertSee('Connect Google Calendar')
            ->assertSee('Weekly servings');
    }

    public function test_a_calendar_can_be_chosen(): void
    {
        $this->connected(calendarId: null);
        Http::fake(['www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
            'items' => [
                ['id' => 'primary', 'summary' => 'Dustin', 'primary' => true],
                ['id' => 'meals@g', 'summary' => 'Meals'],
            ],
        ])]);

        // The one actually called "Meals" is offered first.
        $this->actingAs($this->user)->get(route('settings'))
            ->assertOk()
            ->assertSeeInOrder(['Meals', 'Dustin']);

        $this->actingAs($this->user)
            ->post(route('google.calendar'), ['calendar_id' => 'meals@g', 'calendar_name' => 'Meals'])
            ->assertRedirect(route('settings'));

        $this->assertTrue(GoogleCredential::current()->isReady());
    }

    public function test_disconnecting_forgets_everything(): void
    {
        $this->connected();
        Http::fake();

        $this->actingAs($this->user)->post(route('google.disconnect'))->assertRedirect(route('settings'));

        $credential = GoogleCredential::current();
        $this->assertFalse($credential->isConnected());
        $this->assertNull($credential->calendar_id);
    }

    public function test_settings_require_authentication(): void
    {
        $this->get(route('settings'))->assertRedirect('/login');
        $this->get(route('google.connect'))->assertRedirect('/login');
    }

    public function test_household_settings_can_be_saved(): void
    {
        $this->actingAs($this->user)->post(route('settings.update'), [
            'kids_this_weekend' => '1',
            'shopping_day_of_week' => 6,
            'diet_mode' => 'keto',
            'timezone' => 'America/Chicago',
        ])->assertRedirect();

        $settings = HouseholdSetting::current();
        $this->assertTrue($settings->kids_this_weekend);
        $this->assertSame(6, $settings->shopping_day_of_week);
        $this->assertSame('keto', $settings->diet_mode);
        $this->assertSame('America/Chicago', $settings->timezone);
    }
}
