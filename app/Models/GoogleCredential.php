<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The household's single Google connection (spec 4.8).
 */
class GoogleCredential extends Model
{
    use HasUuids;

    protected $fillable = [
        'access_token', 'refresh_token', 'expires_at', 'scopes',
        'google_email', 'calendar_id', 'calendar_name', 'last_error', 'last_synced_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            // A refresh token is a standing key to the account; it should not
            // be readable from a database dump or a stray log line.
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * The single row, created on demand.
     */
    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    public function isConnected(): bool
    {
        return filled($this->refresh_token);
    }

    /**
     * Ready to write events: connected, and pointed at a calendar.
     */
    public function isReady(): bool
    {
        return $this->isConnected() && filled($this->calendar_id);
    }

    /**
     * Refreshed a minute early, so a token does not expire mid-request.
     */
    public function needsRefresh(): bool
    {
        return blank($this->access_token)
            || $this->expires_at === null
            || $this->expires_at->subMinute()->isPast();
    }

    public function noteFailure(string $message): void
    {
        $this->update(['last_error' => mb_substr($message, 0, 240)]);
    }

    public function noteSuccess(): void
    {
        $this->update(['last_error' => null, 'last_synced_at' => now()]);
    }
}
