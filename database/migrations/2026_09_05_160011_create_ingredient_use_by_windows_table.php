<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Not named in spec 3, but spec 4.1 requires a use-by window tracked
     * "per ingredient, per week" with an editable purchase date — that needs
     * somewhere to live. One row per ingredient per plan week; a second recipe
     * using the same ingredient extends this row rather than adding another.
     */
    public function up(): void
    {
        Schema::create('ingredient_use_by_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ingredient_id')->constrained()->cascadeOnDelete();
            // Monday of the plan week this window belongs to.
            $table->date('week_start_date');
            // Defaults to the upcoming shopping day, editable (spec 4.1).
            $table->date('purchase_date');
            $table->date('expires_on');
            $table->timestamps();

            $table->unique(['ingredient_id', 'week_start_date']);
            $table->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_use_by_windows');
    }
};
