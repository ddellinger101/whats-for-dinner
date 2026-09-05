<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ingredient_id')->constrained()->cascadeOnDelete();
            // v1 is a boolean "have stock" only, not quantity tracking (spec 3).
            // Cleared manually by the user when stock runs out.
            $table->boolean('has_stock')->default(true);
            $table->string('note')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            $table->unique('ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_flags');
    }
};
