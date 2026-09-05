<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-row table holding the household-wide toggles the spec refers to but
     * does not model: the "kids this weekend" switch (spec 3), the shopping day
     * that seeds use-by purchase dates (spec 4.1), and the active diet mode
     * (spec 4.2.2, spec 5).
     */
    public function up(): void
    {
        Schema::create('household_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->boolean('kids_this_weekend')->default(false);
            // 0 = Sunday .. 6 = Saturday. Sunday by default: planning day.
            $table->unsignedTinyInteger('shopping_day_of_week')->default(0);
            // Null = no diet filter active. 'keto' is the only v1 mode.
            $table->string('diet_mode')->nullable();
            // Google Calendar target for the one-way push (spec 4.8).
            $table->string('google_calendar_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_settings');
    }
};
