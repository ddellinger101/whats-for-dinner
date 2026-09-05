<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('created_from_import');
            // The page the recipe came from, kept separately from recipe_links
            // because this one is an attribution rather than a convenience.
            $table->string('source_url', 2048)->nullable()->after('source');
            $table->string('source_name')->nullable()->after('source_url');
            // Lets a search result show "already in your recipes" instead of
            // silently creating a second copy.
            $table->string('external_id')->nullable()->after('source_name');

            $table->index('source');
            $table->index('external_id');
        });

        // The 143 rows already here came from the spreadsheet, and the existing
        // boolean says which those are.
        DB::table('recipes')->where('created_from_import', true)->update(['source' => 'spreadsheet']);
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['external_id']);
            $table->dropColumn(['source', 'source_url', 'source_name', 'external_id']);
        });
    }
};
