<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What each planned meal contributes to a grocery line.
 *
 * A line used to be one row per meal, so a week with olive oil in three
 * recipes put olive oil on the list three times and left the shopper to add it
 * up. One line per thing needs the contributions recorded separately —
 * otherwise dropping one of those three meals could only delete the whole line
 * or leave it overstated.
 *
 * The old single source_component_id becomes the first row of this table and
 * is then dropped, rather than being left as a second, disagreeing answer to
 * the same question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grocery_line_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('grocery_list_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('meal_component_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->timestamps();

            // A meal contributes to a line once; a second helping of the same
            // ingredient adds to the amount rather than making another row.
            //
            // Named, because the one Laravel derives from these two columns is
            // 66 characters and MySQL stops at 64. SQLite accepted it, so this
            // only surfaced on the server.
            $table->unique(['grocery_list_item_id', 'meal_component_id'], 'grocery_line_sources_unique');
        });

        foreach (DB::table('grocery_list_items')->whereNotNull('source_component_id')->get() as $item) {
            DB::table('grocery_line_sources')->insert([
                'id' => (string) Str::uuid(),
                'grocery_list_item_id' => $item->id,
                'meal_component_id' => $item->source_component_id,
                'quantity' => $item->planned_quantity ?? $item->quantity,
                'unit' => $item->unit,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        /*
         * Three statements, in this order, because the two engines object for
         * different reasons. The column carried a foreign key and an index of
         * its own: MySQL will not drop the index while the key still needs it,
         * and SQLite will not drop the column while the index still names it.
         * Key, then index, then column satisfies both.
         */
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->dropForeign(['source_component_id']);
        });

        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->dropIndex(['source_component_id']);
        });

        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->dropColumn('source_component_id');
        });
    }

    public function down(): void
    {
        Schema::table('grocery_list_items', function (Blueprint $table) {
            $table->foreignUuid('source_component_id')->nullable()->constrained('meal_components')->nullOnDelete();
            $table->index('source_component_id');
        });

        foreach (DB::table('grocery_line_sources')->orderBy('created_at')->get() as $source) {
            DB::table('grocery_list_items')
                ->where('id', $source->grocery_list_item_id)
                ->whereNull('source_component_id')
                ->update(['source_component_id' => $source->meal_component_id]);
        }

        Schema::dropIfExists('grocery_line_sources');
    }
};
