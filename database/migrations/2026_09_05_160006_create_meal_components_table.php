<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('meal_plan_entry_id')->constrained()->cascadeOnDelete();
            $table->string('component_type');
            $table->foreignUuid('recipe_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('simple_item_id')->nullable()->constrained()->nullOnDelete();
            // Only primary components drive protein rotation and use-up boosting
            // (spec 3, MealComponent / 4.2).
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('servings_needed');
            $table->timestamps();

            $table->index(['meal_plan_entry_id', 'is_primary']);
            $table->index('recipe_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_components');
    }
};
