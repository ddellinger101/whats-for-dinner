<?php

namespace App\Http\Controllers;

use App\Enums\CategoryTag;
use App\Enums\ProteinType;
use App\Enums\Rating;
use App\Jobs\ImportRecipeDetails;
use App\Models\HouseholdSetting;
use App\Models\Recipe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Spec 5, Recipe Browser: everything is browsable, including thumbs-down
 * recipes, which appear greyed out with an un-grey action rather than hidden.
 */
class RecipeController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $protein = $request->query('protein');
        $tag = $request->query('tag');

        $recipes = Recipe::query()
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($protein, fn ($q) => $q->where('protein_type', $protein))
            ->when($tag, fn ($q) => $q->whereJsonContains('category_tags', $tag))
            ->orderByDesc('times_made')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('recipes.index', [
            'recipes' => $recipes,
            'search' => $search,
            'protein' => $protein,
            'tag' => $tag,
            'proteins' => ProteinType::cases(),
            'tags' => CategoryTag::cases(),
            'dietMode' => HouseholdSetting::current()->diet_mode,
        ]);
    }

    public function show(Recipe $recipe): View
    {
        $recipe->load('ingredients.inventoryFlag');

        return view('recipes.show', [
            'recipe' => $recipe,
            'ratings' => Rating::cases(),
        ]);
    }

    /**
     * Spec 4.4. A just_ok rating is the one that wants a note ("here's how to
     * improve it"), so the notes field travels with the rating.
     */
    public function rate(Request $request, Recipe $recipe): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'string', 'in:'.implode(',', array_column(Rating::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $recipe->update([
            'rating' => Rating::from($validated['rating']),
            'notes' => $validated['notes'] ?? $recipe->notes,
        ]);

        return back()->with('status', 'Rating saved.');
    }

    /**
     * Spec 4.7: ask for an ingredient import by hand, for when the automatic
     * attempt on first selection found nothing, or the link has since been fixed.
     */
    public function importDetails(Recipe $recipe): RedirectResponse
    {
        if (($recipe->recipe_links ?? []) === []) {
            return back()->withErrors(['recipe' => 'This recipe has no link to import from.']);
        }

        ImportRecipeDetails::dispatch($recipe->id);

        return back()->with('status', 'Fetching ingredients from the recipe link. Check back in a moment.');
    }

    /**
     * Spec 3/4.2.5: times_made drives history and last_cooked_on drives the
     * recency deprioritisation, so both move together.
     */
    public function markCooked(Recipe $recipe): RedirectResponse
    {
        $recipe->update([
            'times_made' => $recipe->times_made + 1,
            'last_cooked_on' => Carbon::today(),
        ]);

        return back()->with('status', "Marked {$recipe->name} as made.");
    }
}
