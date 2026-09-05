<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stored per line rather than derived on read, so that a correction sticks:
     * an item put right by hand must stay put, and a manual line has no
     * ingredient to derive anything from.
     */
    public function up(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->string('aisle')->nullable()->after('unit');
            $table->index('aisle');
        });
    }

    public function down(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->dropIndex(['aisle']);
            $table->dropColumn('aisle');
        });
    }
};
