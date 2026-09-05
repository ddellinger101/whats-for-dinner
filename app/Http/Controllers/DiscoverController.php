<?php

namespace App\Http\Controllers;

use App\Enums\CategoryTag;
use App\Enums\ProteinType;
use App\Models\HouseholdSetting;
use App\Services\Discovery\DiscoveredRecipe;
use App\Services\Discovery\DiscoveredRecipeImporter;
use App\Services\Discovery\RecipeSearchQuery;
use App\Services\Discovery\SerpApiRecipeSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * "Try something new" — recipes from outside the household's own library.
 *
 * A result here is only a pointer. Adding one imports it properly, so from
 * that moment it ranks, rates, expires and shops exactly like every other
 * recipe rather than living in a separate bookmark list.
 */
class DiscoverController extends Controller
{
    public function __construct(
        private readonly SerpApiRecipeSearch $search = new SerpApiRecipeSearch,
        private readonly DiscoveredRecipeImporter $importer = new DiscoveredRecipeImporter,
    ) {}

    public function index(Request $request): View
    {
        $settings = HouseholdSetting::current();

        $query = new RecipeSearchQuery(
            text: trim((string) $request->query('q', '')),
            protein: ProteinType::tryFrom((string) $request->query('protein')),
            tag: CategoryTag::tryFrom((string) $request->query('tag')),
            // The diet filter follows the household setting by default, so a
            // keto week does not have to be re-stated on every search.
            ketoOnly: $request->boolean('keto', $settings->diet_mode === 'keto'),
        );

        $results = collect();
        $error = null;

        if (! $query->isEmpty()) {
            try {
                $results = $this->search->search($query);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        // Marked so a result already in the library offers a link to it rather
        // than a button that would quietly make a second copy.
        $existing = $results
            ->mapWithKeys(fn (DiscoveredRecipe $r) => [$r->externalId() => $this->importer->existing($r)])
            ->filter();

        return view('discover.index', [
            'query' => $query,
            'results' => $results,
            'existing' => $existing,
            'error' => $error,
            'configured' => $this->search->isConfigured(),
            'providerName' => $this->search->name(),
            'search' => $query->text,
            'protein' => $request->query('protein'),
            'tag' => $request->query('tag'),
            'keto' => $query->ketoOnly,
            'proteins' => ProteinType::cases(),
            'cuisines' => CategoryTag::cuisines(),
            'styles' => CategoryTag::styles(),
            // Carried through so "add" can return to the slot being filled.
            'returnTo' => $request->query('return_to'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'url' => ['required', 'url', 'max:2048'],
            'source' => ['nullable', 'string', 'max:120'],
            'thumbnail' => ['nullable', 'url', 'max:2048'],
            'return_to' => ['nullable', 'string', 'max:250'],
        ]);

        $discovered = new DiscoveredRecipe(
            title: $validated['title'],
            url: $validated['url'],
            source: $validated['source'] ?? null,
            thumbnail: $validated['thumbnail'] ?? null,
        );

        $recipe = $this->importer->import($discovered);

        $note = match (true) {
            $recipe->ingredients_status->hasIngredients() => 'Added with its ingredients.',
            // Distinguished on purpose: a site that refuses automated readers
            // will refuse again, so pointing at the retry button would waste
            // the household's time.
            $this->importer->lastFetchRefused => 'Added, but that site blocks automated readers. '
                .'Open the recipe and paste its ingredient list in — there is a box for the whole list at once.',
            default => 'Added. Its ingredients could not be read from the page, so add them by hand when you get a moment.',
        };

        // Straight back to the slot being filled, if that is where this started.
        if (filled($validated['return_to'] ?? null) && str_starts_with($validated['return_to'], '/plan/')) {
            return redirect($validated['return_to'])->with('status', "{$recipe->name}: {$note}");
        }

        return redirect()->route('recipes.show', $recipe)->with('status', $note);
    }
}
