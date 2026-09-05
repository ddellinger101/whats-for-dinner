<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            // Remembered so a changed meal updates its event rather than
            // leaving the old one behind and adding a second (spec 4.8).
            $table->string('google_event_id')->nullable()->after('servings_manually_set');
        });

        Schema::table('household_settings', function (Blueprint $table) {
            // The server runs in UTC; a 6pm dinner has to land at 6pm where the
            // household actually lives.
            $table->string('timezone')->default('America/New_York')->after('google_calendar_id');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->dropColumn('google_event_id');
        });

        Schema::table('household_settings', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
