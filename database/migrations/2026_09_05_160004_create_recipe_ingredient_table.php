<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ingredient', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->cascadeOnDelete();
            // Stored per serving so scaling is a single multiply (spec 4.3).
            $table->decimal('quantity_per_serving', 10, 3)->nullable();
            $table->string('unit')->nullable();
            $table->timestamps();

            $table->unique(['recipe_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredient');
    }
};
