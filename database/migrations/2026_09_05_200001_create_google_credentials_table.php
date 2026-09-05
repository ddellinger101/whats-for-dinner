<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Google connection for the whole household (spec 4.8).
     *
     * Deliberately not per user: the calendar is shared, so a second
     * authorisation would only mean two apps writing the same events. One
     * account connects, shares the calendar in Google, and both people see it.
     */
    public function up(): void
    {
        Schema::create('google_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Encrypted at the model. A refresh token is a standing key to the
            // account, so it should not be readable from a database dump.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->string('google_email')->nullable();
            $table->string('calendar_id')->nullable();
            $table->string('calendar_name')->nullable();
            // Why the last attempt failed, shown in the app rather than left in
            // a log — an integration that dies silently stays dead for weeks.
            $table->string('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_credentials');
    }
};
