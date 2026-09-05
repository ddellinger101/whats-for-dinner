<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recipe images are not in the spec — added on request. Two sources: scraped
     * from the recipe link (og:image / schema.org image, alongside the 4.7
     * ingredient import) or a photo taken in the kitchen. Both land as a file on
     * the public disk; image_source_url records provenance for scraped ones.
     */
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('notes');
            $table->string('image_source_url', 2048)->nullable()->after('image_path');
            $table->string('image_status')->default('none')->after('image_source_url');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_source_url', 'image_status']);
        });
    }
};
