<?php

namespace App\Http\Controllers;

use App\Enums\CategoryTag;
use App\Enums\MealType;
use App\Enums\ProteinType;
use App\Enums\Rating;
use App\Jobs\ImportRecipeDetails;
use App\Models\HouseholdSetting;
use App\Models\MealComponent;
use App\Models\Recipe;
use App\Services\InventoryService;
use App\Services\MealPlanner;
use Illuminate\Support\Facades\Storage;
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

    public function create(): View
    {
        return view('recipes.form', [
            'recipe' => new Recipe,
            'proteins' => ProteinType::cases(),
            'mealTypes' => MealType::cases(),
            'cuisines' => CategoryTag::cuisines(),
            'styles' => CategoryTag::styles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateDetails($request);

        $recipe = Recipe::create($validated + ['created_from_import' => false]);

        return redirect()->route('recipes.show', $recipe)
            ->with('status', "{$recipe->name} created. Add its ingredients below.");
    }

    public function edit(Recipe $recipe): View
    {
        return view('recipes.form', [
            'recipe' => $recipe,
            'proteins' => ProteinType::cases(),
            'mealTypes' => MealType::cases(),
            'cuisines' => CategoryTag::cuisines(),
            'styles' => CategoryTag::styles(),
        ]);
    }

    public function update(Request $request, Recipe $recipe): RedirectResponse
    {
        $recipe->update($this->validateDetails($request));

        return redirect()->route('recipes.show', $recipe)->with('status', 'Recipe saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateDetails(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'protein_type' => ['required', 'string', 'in:'.implode(',', array_column(ProteinType::cases(), 'value'))],
            'meal_type' => ['required', 'string', 'in:'.implode(',', array_column(MealType::cases(), 'value'))],
            'base_servings' => ['required', 'integer', 'min:1', 'max:60'],
            'category_tags' => ['nullable', 'array'],
            'category_tags.*' => ['string', 'in:'.implode(',', array_column(CategoryTag::cases(), 'value'))],
            'links' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $links = collect(preg_split('/\r\n|\r|\n/', (string) ($validated['links'] ?? '')))
            ->map(fn ($line) => trim((string) $line))
            ->filter(fn ($line) => $line !== '' && filter_var($line, FILTER_VALIDATE_URL) !== false)
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $tags = $validated['category_tags'] ?? [];

        return [
            'name' => $validated['name'],
            'protein_type' => $validated['protein_type'],
            'meal_type' => $validated['meal_type'],
            'base_servings' => $validated['base_servings'],
            'category_tags' => $tags,
            'recipe_links' => $links,
            'notes' => $validated['notes'] ?? null,
            // Spec 3: the keto tag and the keto flag are two views of one fact,
            // so setting either has to set the other.
            'is_keto' => in_array(CategoryTag::Keto->value, $tags, true),
        ];
    }

    /**
     * Delete a recipe and everything that only made sense because of it.
     *
     * The recipe_id foreign key nulls on delete rather than cascading, so left
     * alone this would strand meal components pointing at nothing — blank chips
     * in the week's plan, with their groceries still on the list. Each planned
     * use is removed properly instead, which also retracts what it added.
     */
    public function destroy(Recipe $recipe, MealPlanner $planner): RedirectResponse
    {
        $name = $recipe->name;

        $planned = MealComponent::where('recipe_id', $recipe->id)->get();

        foreach ($planned as $component) {
            $planner->removeComponent($component);
        }

        if ($recipe->image_path) {
            Storage::disk('public')->delete($recipe->image_path);
        }

        $recipe->ingredients()->detach();
        $recipe->delete();

        $note = $planned->isNotEmpty()
            ? " It was removed from {$planned->count()} planned ".str('meal')->plural($planned->count()).'.'
            : '';

        return redirect()->route('recipes')->with('status', "Deleted {$name}.{$note}");
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

        // Cooking it is the other moment the app can observe stock changing.
        // Deducted at the size it was actually planned for, so what leaves the
        // pantry matches what the grocery list bought for it.
        $servings = MealComponent::query()
            ->where('recipe_id', $recipe->id)
            ->whereHas('mealPlanEntry', fn ($q) => $q->whereDate('date', '<=', Carbon::today()->toDateString()))
            ->latest('created_at')
            ->value('servings_needed');

        $touched = (new InventoryService)->consumeForRecipe($recipe, $servings ?? $recipe->base_servings);

        return back()->with('status', $touched > 0
            ? "Marked {$recipe->name} as made, and took its ingredients out of the pantry."
            : "Marked {$recipe->name} as made.");
    }
}
