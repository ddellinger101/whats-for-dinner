<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('protein_type')->default('none');
            $table->string('meal_type')->default('dinner');
            // Fixed vocabulary from spec 3; stored as JSON so a recipe can carry
            // several tags without a pivot table for nine known values.
            $table->json('category_tags')->nullable();
            $table->boolean('is_keto')->default(false);
            // Semicolon-split on import (spec 4.7); tried in order by the importer.
            $table->json('recipe_links')->nullable();
            $table->unsignedSmallInteger('base_servings')->default(4);
            $table->string('rating')->default('unrated');
            $table->unsignedInteger('times_made')->default(0);
            $table->string('ingredients_status')->default('not_yet_added');
            $table->text('notes')->nullable();
            $table->boolean('created_from_import')->default(false);
            // Drives the recency deprioritisation in spec 4.2.5.
            $table->date('last_cooked_on')->nullable();
            $table->timestamps();

            $table->index('protein_type');
            $table->index('rating');
            $table->index('is_keto');
            $table->index('last_cooked_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
