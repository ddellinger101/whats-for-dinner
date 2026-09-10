<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a planned meal was actually made.
 *
 * Recorded per component rather than per recipe, because that is the thing on
 * the plate: a lunch is three sandwiches, each its own component, and marking
 * one made says nothing about the others.
 *
 * It also stops the pantry being drawn down twice. "Did I already tick this?"
 * is a real question after a day of them, and without a mark the only way to
 * answer it was to remember.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_components', function (Blueprint $table) {
            $table->timestamp('made_at')->nullable()->after('servings_needed');
        });
    }

    public function down(): void
    {
        Schema::table('meal_components', function (Blueprint $table) {
            $table->dropColumn('made_at');
        });
    }
};
