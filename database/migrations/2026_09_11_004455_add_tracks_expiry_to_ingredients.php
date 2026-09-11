<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this ingredient is worth giving a use-by date at all.
 *
 * Bread, milk, eggs and butter are perishable in the abstract and never
 * actually go off in this house — they are eaten first. A date on them is a
 * weekly false alarm in the use-these-up list, which is the one part of the
 * pantry that has to stay worth reading.
 *
 * On the ingredient rather than on the pantry row, because clearing the date
 * on a carton of milk should mean something about milk. Otherwise the next
 * shop puts it straight back, and it has to be cleared again every week.
 *
 * Distinct from is_staple: a staple is also kept off the grocery list, and
 * milk very much needs buying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('tracks_expiry')->default(true)->after('is_staple');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('tracks_expiry');
        });
    }
};
