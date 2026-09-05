<?php

namespace App\Services\Google;

use App\Models\GoogleCredential;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Spec 4.8's authorisation half.
 *
 * Written against the endpoints directly rather than pulling in Google's SDK:
 * this needs one flow and one API call, and the SDK is a large dependency to
 * carry on a shared host for that.
 */
class GoogleOAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    /**
     * Events, plus a read-only view of the calendar list so the household can
     * pick their existing "Meals" calendar instead of typing an opaque id.
     * Deliberately not full calendar access: the app never needs to read what
     * is in anyone's diary.
     *
     * @var list<string>
     */
    public const SCOPES = [
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
        'openid',
        'email',
    ];

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function authorisationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            // offline + consent is what actually returns a refresh token.
            // Without prompt=consent Google withholds it on a re-authorisation,
            // and the connection silently becomes one that cannot renew itself.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): GoogleCredential
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorFrom($response->json(), 'Could not complete the connection.'));
        }

        $data = $response->json();
        $credential = GoogleCredential::current();

        $credential->update([
            'access_token' => $data['access_token'] ?? null,
            // Google only returns a refresh token on first consent, so an
            // absent one must not wipe the one already held.
            'refresh_token' => $data['refresh_token'] ?? $credential->refresh_token,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'scopes' => $data['scope'] ?? null,
            'google_email' => $this->emailFor($data['access_token'] ?? null) ?? $credential->google_email,
            'last_error' => null,
        ]);

        return $credential->fresh();
    }

    /**
     * Exchange the refresh token for a new access token.
     *
     * An invalid_grant here is the failure that killed the household's previous
     * app: a consent screen left in Testing has its refresh tokens revoked
     * after seven days. It is recorded on the credential so the app can say so
     * rather than failing into a log nobody reads.
     */
    public function refresh(GoogleCredential $credential): GoogleCredential
    {
        if (blank($credential->refresh_token)) {
            throw new RuntimeException('Google is not connected.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $credential->refresh_token,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $error = $response->json('error');

            $message = $error === 'invalid_grant'
                ? 'Google rejected the saved authorisation. Reconnect, and make sure the consent screen is published to Production — tokens expire after 7 days while it is in Testing.'
                : $this->errorFrom($response->json(), 'Could not refresh the Google connection.');

            $credential->noteFailure($message);

            throw new RuntimeException($message);
        }

        $data = $response->json();

        $credential->update([
            'access_token' => $data['access_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'last_error' => null,
        ]);

        return $credential->fresh();
    }

    /**
     * A usable access token, refreshed if it has aged out.
     */
    public function accessToken(GoogleCredential $credential): string
    {
        if ($credential->needsRefresh()) {
            $credential = $this->refresh($credential);
        }

        return (string) $credential->access_token;
    }

    public function disconnect(GoogleCredential $credential): void
    {
        // Best effort: tell Google to drop it, then forget it locally either
        // way. A revoke that fails must not leave the app still holding a token.
        if (filled($credential->refresh_token)) {
            try {
                Http::asForm()->post(self::REVOKE_URL, ['token' => $credential->refresh_token]);
            } catch (\Throwable) {
                // Nothing useful to do; the local delete below is what matters.
            }
        }

        $credential->update([
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'scopes' => null,
            'google_email' => null,
            'calendar_id' => null,
            'calendar_name' => null,
            'last_error' => null,
            'last_synced_at' => null,
        ]);
    }

    private function emailFor(?string $accessToken): ?string
    {
        if (! $accessToken) {
            return null;
        }

        try {
            $response = Http::withToken($accessToken)->get(self::USERINFO_URL);

            return $response->successful() ? $response->json('email') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function errorFrom(?array $body, string $fallback): string
    {
        $description = $body['error_description'] ?? null;
        $error = $body['error'] ?? null;

        return $description ? "{$fallback} ({$description})" : ($error ? "{$fallback} ({$error})" : $fallback);
    }
}
