<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a human chose this ingredient's section.
 *
 * The category is a guess made from a name, and the guesser is re-run over the
 * whole archive whenever its rules improve. Without a mark, that re-run walks
 * straight over every correction someone made by hand — so a roast filed under
 * produce, fixed once, goes back to produce the next time the rules change.
 *
 * A person who has looked at the thing beats a keyword list that has only read
 * its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->boolean('category_set_by_hand')->default(false)->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('category_set_by_hand');
        });
    }
};
