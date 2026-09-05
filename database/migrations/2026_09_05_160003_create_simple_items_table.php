<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simple_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // Compound items (Turkey Sandwich) carry their grocery breakdown;
            // single-concept items (Grapes) leave this empty and the name itself
            // becomes the grocery line (spec 4.5).
            $table->json('grocery_breakdown')->nullable();
            $table->string('meal_type_hint')->default('lunch');
            // Set once the user has been offered the breakdown prompt, so a
            // skipped prompt is not re-asked every time (spec 4.5).
            $table->boolean('breakdown_prompted')->default(false);
            $table->timestamps();

            $table->unique('name');
            $table->index('meal_type_hint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simple_items');
    }
};
