<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_household_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // 0 = Sunday .. 6 = Saturday, matching Carbon's dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');
            // Weekdays hold the same value in both columns; weekend days differ,
            // and the household_settings "kids this weekend" toggle picks between
            // them so a shifting custody schedule needs one switch, not an edit
            // to every row (spec 3, WeeklyHouseholdSchedule).
            $table->unsignedSmallInteger('servings_with_kids');
            $table->unsignedSmallInteger('servings_without_kids');
            $table->boolean('varies_by_custody')->default(false);
            $table->timestamps();

            $table->unique('day_of_week');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_household_schedules');
    }
};
