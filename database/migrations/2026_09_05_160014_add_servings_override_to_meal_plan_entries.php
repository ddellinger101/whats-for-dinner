<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 4.3 derives household size from the weekly schedule, but holidays and
     * guests break that rotation constantly. This flag records that a human
     * chose the number, so recalculating a week never overwrites it.
     */
    public function up(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->boolean('servings_manually_set')->default(false)->after('household_size_used');
        });
    }

    public function down(): void
    {
        Schema::table('meal_plan_entries', function (Blueprint $table) {
            $table->dropColumn('servings_manually_set');
        });
    }
};
