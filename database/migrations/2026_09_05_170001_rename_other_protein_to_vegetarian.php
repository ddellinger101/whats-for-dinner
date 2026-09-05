<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * "Other" became "Vegetarian" on request.
     *
     * Worth knowing: the source spreadsheet's Other sheet was a catch-all for
     * dishes whose protein was unspecified — Pizza, Leftovers, "Burrito Skillet
     * (protein unspecified)" — so these rows now read as vegetarian whether or
     * not they are. That was accepted as a correct-as-you-go trade.
     */
    public function up(): void
    {
        DB::table('recipes')->where('protein_type', 'other')->update(['protein_type' => 'vegetarian']);
    }

    public function down(): void
    {
        DB::table('recipes')->where('protein_type', 'vegetarian')->update(['protein_type' => 'other']);
    }
};
