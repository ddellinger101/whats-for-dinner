<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repeater_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('item_name');
            $table->unsignedSmallInteger('frequency_days');
            $table->date('last_purchased_date')->nullable();
            // Stored rather than computed so the grocery list can query due items
            // directly; recomputed on each purchase (spec 3, 4.6).
            $table->date('next_due_date')->nullable();
            $table->timestamps();

            $table->unique('item_name');
            $table->index('next_due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repeater_items');
    }
};
