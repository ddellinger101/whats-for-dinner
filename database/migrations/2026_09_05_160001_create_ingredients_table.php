<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('category');
            // Typical days-good-for once purchased/opened. Seeded from the
            // category default (spec 7), editable per ingredient.
            $table->unsignedSmallInteger('shelf_life_days');
            $table->string('default_unit')->nullable();
            $table->timestamps();

            $table->unique('name');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
