<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the spice rack: things always in, which no recipe should be able to
 * put on the grocery list.
 *
 * A column rather than an inventory flag, because the two say different
 * things. A flag records an observation that can go stale — it is decremented
 * by cooking and cleared by "mark gone". This is a standing fact about the
 * ingredient, and nothing routine should be able to switch it off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('is_staple')->default(false)->after('category');
            $table->index('is_staple');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex(['is_staple']);
            $table->dropColumn('is_staple');
        });
    }
};
