<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cleared and removed lines are kept rather than destroyed.
     *
     * The list is now its own memory: past entries feed the add-item autofill,
     * and the aisle an item was last filed under is how a hand correction
     * teaches the guesser. Hard-deleting on "clear purchased" would wipe both
     * every time the shopping was tidied up, so the app would forget precisely
     * what it had just learned.
     */
    public function up(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
