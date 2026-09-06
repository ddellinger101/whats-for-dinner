<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * What the week's meals actually call for, kept alongside what is being
     * bought.
     *
     * These are two different facts. The plan needs two chicken breasts; the
     * shop sells them in eights. Without somewhere to keep the first, an edited
     * line just looks like the plan wanted eight, and next week nobody
     * remembers why — and the surplus that should be used up looks like a
     * miscalculation instead of a deliberate buy.
     */
    public function up(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->decimal('planned_quantity', 10, 3)->nullable()->after('quantity');
        });

        // Nothing has been edited yet, so what is on the list is what was asked
        // for.
        DB::table('grocery_list_items')->whereNotNull('quantity')
            ->update(['planned_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->dropColumn('planned_quantity');
        });
    }
};
