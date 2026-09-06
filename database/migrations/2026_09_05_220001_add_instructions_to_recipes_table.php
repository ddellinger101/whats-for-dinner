<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec 5 asks the What's For Dinner screen to show "instructions/link", and
     * only the link was ever built — so standing at the stove meant opening the
     * original page anyway, which is most of what the screen exists to avoid.
     *
     * Stored as an ordered list of steps rather than one blob, so it renders as
     * a numbered list you can keep your place in.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->json('instructions')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn('instructions');
        });
    }
};
