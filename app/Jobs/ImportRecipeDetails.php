<?php

namespace App\Jobs;

use App\Enums\IngredientsStatus;
use App\Models\Recipe;
use App\Services\Scraping\RecipeDetailImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Spec 4.7 — fetching a recipe page is slow and frequently fails, so it runs
 * off the request. Nobody planning dinner should wait on a recipe blog.
 */
class ImportRecipeDetails implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public readonly string $recipeId) {}

    /**
     * Two people can select the same recipe at once; without this the pair
     * would race and one would overwrite the other's ingredient rows.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->recipeId))->dontRelease()];
    }

    public function handle(RecipeDetailImporter $importer): void
    {
        $recipe = Recipe::find($this->recipeId);

        // Nothing to do if it was deleted, or someone entered ingredients by
        // hand while this sat in the queue — manual entry outranks a scrape.
        if (! $recipe || $recipe->ingredients_status === IngredientsStatus::ManuallyEntered) {
            return;
        }

        $importer->import($recipe);
    }
}
