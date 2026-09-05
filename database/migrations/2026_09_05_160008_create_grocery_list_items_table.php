<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grocery_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('item_name');
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('unit')->nullable();
            $table->string('source');
            $table->string('status')->default('needed');
            $table->date('added_date');
            // Traceability back to the slot component that generated this line,
            // so removing a meal can retract its auto-added items (spec 3).
            $table->foreignUuid('source_component_id')->nullable()
                ->references('id')->on('meal_components')->nullOnDelete();
            // Set for auto_recipe lines so the "from freezer" note and the
            // has-stock skip can be resolved back to the ingredient (spec 4.6).
            $table->foreignUuid('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'added_date']);
            $table->index('source_component_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grocery_list_items');
    }
};
