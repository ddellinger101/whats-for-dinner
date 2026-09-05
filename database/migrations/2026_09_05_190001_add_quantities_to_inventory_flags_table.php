<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grows the has-stock flag into a rough pantry.
     *
     * Spec 3 scoped v1 to a boolean and spec 6 deferred quantities to v2; this
     * pulls that forward on request. It stays deliberately approximate — nobody
     * weighs what is left of a bag of onions — so quantity is nullable and null
     * means "some, amount unknown", which is a different and useful answer from
     * zero.
     */
    public function up(): void
    {
        Schema::table('inventory_flags', function (Blueprint $table) {
            $table->decimal('quantity', 10, 3)->nullable()->after('has_stock');
            $table->string('unit')->nullable()->after('quantity');
            $table->date('acquired_on')->nullable()->after('unit');
            // Cached rather than derived on read so a "use it by Thursday"
            // correction sticks without editing the ingredient's shelf life
            // for every future purchase.
            $table->date('expires_on')->nullable()->after('acquired_on');
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_flags', function (Blueprint $table) {
            $table->dropIndex(['expires_on']);
            $table->dropColumn(['quantity', 'unit', 'acquired_on', 'expires_on']);
        });
    }
};
