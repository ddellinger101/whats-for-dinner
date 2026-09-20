<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a bought line was actually taken into the pantry.
 *
 * Ticking something off used to stock the pantry there and then, inside the
 * request, which is most of why the tick felt slow. It is deferred now, and
 * deferring needs a record of what has already happened: a job that runs twice
 * would add the shopping twice, since buying more of something adds to what is
 * there rather than replacing it.
 *
 * Deliberately never cleared. Unticking does not take the shopping back out —
 * it never did — so leaving the mark is what stops a tick, untick, tick from
 * stocking the same two pounds of mince three times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->dropColumn('settled_at');
        });
    }
};
