<?php

namespace App\Http\Controllers;

use App\Enums\ImageStatus;
use App\Enums\IngredientsStatus;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\IngredientResolver;
use App\Support\IngredientLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Spec 4.7's manual fallback: when auto-import finds nothing — which is the
 * outcome for over half the archive — ingredients have to be enterable by hand,
 * or those recipes can never take part in use-by tracking or the grocery list.
 *
 * Quantities are entered as the amount the whole recipe needs, because that is
 * how they are written on the page being copied from. They are stored per
 * serving so scaling stays a single multiply (spec 4.3).
 */
class RecipeIngredientController extends Controller
{
    public function __construct(
        private readonly IngredientResolver $ingredients = new IngredientResolver,
    ) {}

    public function store(Request $request, Recipe $recipe): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'unit' => ['nullable', 'string', 'max:20'],
        ]);

        $ingredient = $this->ingredients->resolve($validated['name']);

        $this->attach($recipe, $ingredient, $validated['quantity'] ?? null, $validated['unit'] ?? null);
        $this->markManual($recipe);

        return back()->with('status', "Added {$ingredient->name}.");
    }

    /**
     * Paste a whole ingredient list at once. The same parser the scraper uses
     * handles the lines, so "2 1/2 cups (300g) flour, sifted" works whether it
     * arrived from a website or a clipboard.
     */
    public function storeBulk(Request $request, Recipe $recipe): RedirectResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'string', 'max:8000'],
        ]);

        $lines = collect(preg_split('/\r\n|\r|\n/', $validated['lines']))
            ->map(fn ($line) => trim((string) $line))
            ->filter(fn ($line) => $line !== '')
            ->take(100);

        if ($lines->isEmpty()) {
            return back()->withErrors(['lines' => 'Nothing to add.']);
        }

        $added = 0;

        DB::transaction(function () use ($lines, $recipe, &$added) {
            foreach ($lines as $line) {
                $parsed = IngredientLine::parse($line);

                // Belt and braces after a parsing fault produced empty names
                // and silently collapsed six ingredients into one blank row.
                if (trim($parsed->name) === '') {
                    continue;
                }

                $ingredient = $this->ingredients->resolve($parsed->name);

                if ($this->attach($recipe, $ingredient, $parsed->quantity, $parsed->unit)) {
                    $added++;
                }
            }

            $this->markManual($recipe);
        });

        return back()->with('status', "Added {$added} ".str('ingredient')->plural($added).'.');
    }

    public function destroy(Recipe $recipe, Ingredient $ingredient): RedirectResponse
    {
        $recipe->ingredients()->detach($ingredient->id);

        // Losing the last ingredient puts the recipe back in the "needs
        // ingredients" state, so it is not silently treated as complete.
        if ($recipe->ingredients()->count() === 0) {
            $recipe->update(['ingredients_status' => IngredientsStatus::NotYetAdded]);
        }

        return back()->with('status', "Removed {$ingredient->name}.");
    }

    /**
     * Correcting the yield must not change how much food the recipe makes: the
     * amounts already entered were for the whole recipe, so per-serving figures
     * are recalculated to keep the totals where they were.
     */
    public function updateServings(Request $request, Recipe $recipe): RedirectResponse
    {
        $validated = $request->validate([
            'base_servings' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        $old = max(1, $recipe->base_servings);
        $new = $validated['base_servings'];

        if ($old !== $new) {
            DB::transaction(function () use ($recipe, $old, $new) {
                foreach ($recipe->ingredients as $ingredient) {
                    if ($ingredient->pivot->quantity_per_serving === null) {
                        continue;
                    }

                    $total = $ingredient->pivot->quantity_per_serving * $old;

                    $recipe->ingredients()->updateExistingPivot($ingredient->id, [
                        'quantity_per_serving' => round($total / $new, 4),
                    ]);
                }

                $recipe->update(['base_servings' => $new]);
            });
        }

        return back()->with('status', "Now serving {$new}.");
    }

    /**
     * The method, one step per line.
     *
     * Kept as a list rather than a blob so it renders numbered — the point is
     * being able to keep your place while cooking, which a paragraph does not
     * allow.
     */
    public function updateInstructions(Request $request, Recipe $recipe): RedirectResponse
    {
        $validated = $request->validate([
            'instructions' => ['nullable', 'string', 'max:20000'],
        ]);

        $steps = collect(preg_split('/\R+/u', (string) ($validated['instructions'] ?? '')))
            ->map(fn ($line) => trim((string) $line))
            // Numbers typed by hand are dropped: the list supplies its own.
            ->map(fn (string $line) => (string) (preg_replace('/^\d{1,2}[.)]\s*/u', '', $line) ?? $line))
            ->filter(fn (string $line) => $line !== '')
            ->take(60)
            ->values()
            ->all();

        $recipe->update(['instructions' => $steps]);

        return back()->with('status', $steps === [] ? 'Method cleared.' : 'Method saved.');
    }

    /**
     * Spec addition: photograph a dish in the kitchen. An uploaded photo is
     * marked as user-provided, which permanently protects it from being
     * overwritten by a later scrape.
     */
    public function storePhoto(Request $request, Recipe $recipe): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:10240'],
        ]);

        $file = $request->file('photo');
        $path = 'recipes/'.$recipe->id.'-photo.'.$file->extension();

        // Checked, not assumed. An unwritable directory made put() return false
        // while the recipe was still updated to point at the file, so the app
        // recorded a photo that had never been saved and rendered a broken
        // image with no error anywhere.
        if (! Storage::disk('public')->put($path, $file->get())) {
            return back()->withErrors([
                'photo' => 'The photo could not be saved on the server. Nothing has been changed.',
            ]);
        }

        $previous = $recipe->image_path;

        $recipe->update([
            'image_path' => $path,
            'image_source_url' => null,
            'image_status' => ImageStatus::Uploaded,
        ]);

        // Otherwise the replaced file lingers, one per re-upload.
        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        return back()->with('status', 'Photo saved.');
    }

    public function destroyPhoto(Recipe $recipe): RedirectResponse
    {
        if ($recipe->image_path) {
            Storage::disk('public')->delete($recipe->image_path);
        }

        $recipe->update([
            'image_path' => null,
            'image_source_url' => null,
            'image_status' => ImageStatus::None,
        ]);

        return back()->with('status', 'Photo removed.');
    }

    /**
     * Returns false when the ingredient was already on the recipe.
     */
    private function attach(Recipe $recipe, Ingredient $ingredient, ?float $totalQuantity, ?string $unit): bool
    {
        if ($recipe->ingredients()->whereKey($ingredient->id)->exists()) {
            return false;
        }

        $recipe->ingredients()->attach($ingredient->id, [
            'id' => (string) Str::uuid(),
            // Entered as what the whole recipe needs; stored per serving.
            'quantity_per_serving' => $totalQuantity === null
                ? null
                : round($totalQuantity / max(1, $recipe->base_servings), 4),
            'unit' => $unit,
        ]);

        return true;
    }

    /**
     * Manual entry outranks a scrape: once someone has typed ingredients in, a
     * queued import must not overwrite them (see ImportRecipeDetails).
     */
    private function markManual(Recipe $recipe): void
    {
        $recipe->update(['ingredients_status' => IngredientsStatus::ManuallyEntered]);
    }
}
