<?php

namespace App\Console\Commands;

use App\Enums\ImageStatus;
use App\Models\Recipe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Finds recipes pointing at an image file that is not there.
 *
 * Written after an unwritable directory made photo uploads fail silently: the
 * write returned false, the recipe was updated anyway, and the app rendered a
 * broken image with nothing logged. The write path now checks itself, but rows
 * recorded before that still claim a photo they never had.
 */
class CheckRecipeImages extends Command
{
    protected $signature = 'recipes:check-images {--apply : Clear the ones that are missing}';

    protected $description = 'Find recipes whose image file no longer exists';

    public function handle(): int
    {
        $disk = Storage::disk('public');

        $broken = Recipe::query()
            ->whereNotNull('image_path')
            ->get()
            ->filter(fn (Recipe $recipe) => ! $disk->exists($recipe->image_path));

        if ($broken->isEmpty()) {
            $this->info('Every recipe image is present.');

            return self::SUCCESS;
        }

        foreach ($broken as $recipe) {
            $this->line(sprintf('  %-34s %s', $recipe->name, $recipe->image_path));
        }

        $this->newLine();

        if (! $this->option('apply')) {
            $this->warn($broken->count().' point at a missing file. Re-run with --apply to clear them.');

            return self::SUCCESS;
        }

        foreach ($broken as $recipe) {
            $recipe->update([
                'image_path' => null,
                'image_source_url' => null,
                'image_status' => ImageStatus::None,
            ]);
        }

        $this->info('Cleared '.$broken->count().'. Those recipes can take a photo again.');

        return self::SUCCESS;
    }
}
