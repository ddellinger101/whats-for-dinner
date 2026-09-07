<?php

namespace App\Console\Commands;

use App\Models\GoogleCredential;
use App\Models\MealPlanEntry;
use App\Services\Google\GoogleCalendar;
use App\Services\Google\GoogleOAuth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Says, in one place, whether the calendar connection actually works.
 *
 * The failure that matters here is silent by nature: planning a meal queues a
 * job, the job fails on the far side of a network, and the only trace is a row
 * in failed_jobs. The settings screen does show the last error, but answering
 * "is it fixed now?" meant planning a meal and waiting to see — this asks
 * Google directly instead.
 */
class CheckGoogleCalendar extends Command
{
    protected $signature = 'google:check
        {--clear-failed : Forget the failed sync jobs once it is working}';

    protected $description = 'Check the Google Calendar connection end to end';

    public function handle(GoogleOAuth $oauth, GoogleCalendar $calendar): int
    {
        $credential = GoogleCredential::first();

        $this->newLine();

        if (! $oauth->isConfigured()) {
            $this->line('  <fg=red>x</> No Google client id and secret on this server.');
            $this->line('    Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env.');

            return self::FAILURE;
        }

        $this->line('  <fg=green>ok</> Client id and secret are set.');

        if (! $credential?->isConnected()) {
            $this->line('  <fg=red>x</> Nobody has connected an account yet.');
            $this->line('    Settings -> Connect Google Calendar.');

            return self::FAILURE;
        }

        $this->line("  <fg=green>ok</> Connected as {$credential->google_email}.");

        // The real test. Everything above can look right while Google refuses
        // the credentials, which is exactly what a rotated secret does.
        try {
            $oauth->refresh($credential);
            $this->line('  <fg=green>ok</> Google accepted the credentials.');
        } catch (Throwable $e) {
            $this->line('  <fg=red>x</> Google refused the credentials.');
            $this->line('    '.$e->getMessage());
            $this->explain($e->getMessage());

            return self::FAILURE;
        }

        if (! $credential->isReady()) {
            $this->line('  <fg=yellow>!</> No calendar chosen, so nothing is being written.');
            $this->line('    Settings -> pick a calendar.');

            return self::FAILURE;
        }

        $this->line("  <fg=green>ok</> Writing to \"{$credential->calendar_name}\".");

        $total = MealPlanEntry::count();
        $synced = MealPlanEntry::whereNotNull('google_event_id')->count();
        $failed = DB::table('failed_jobs')->count();

        $this->line("  <fg=green>ok</> {$synced} of {$total} planned slots are on the calendar.");

        if ($synced < $total) {
            $this->line('    Settings -> "Send this week to Google" pushes the rest.');
        }

        if ($failed > 0) {
            $this->line("  <fg=yellow>!</> {$failed} sync ".str('job')->plural($failed).' failed earlier.');

            if ($this->option('clear-failed')) {
                DB::table('failed_jobs')->delete();
                $this->line('    Forgotten.');
            } else {
                $this->line('    Re-run with --clear-failed to forget them.');
            }
        }

        $this->newLine();
        $this->info('The calendar connection is working.');

        return self::SUCCESS;
    }

    /**
     * The two failures worth naming, because neither is fixable from in here
     * and both look identical from the app's side.
     */
    private function explain(string $message): void
    {
        $this->newLine();

        if (str_contains($message, 'client secret')) {
            $this->line('    The secret on this server is not one Google currently accepts.');
            $this->line('    Google Cloud Console -> Credentials -> your OAuth client, then');
            $this->line('    put the active secret into GOOGLE_CLIENT_SECRET in .env and run');
            $this->line('    php artisan config:cache.');

            return;
        }

        if (str_contains($message, 'expired or been revoked')) {
            $this->line('    The refresh token is dead. That happens when the consent screen');
            $this->line('    is left in Testing mode, where Google expires them after a week.');
            $this->line('    Publish the app to Production, then reconnect from Settings.');
        }
    }
}
