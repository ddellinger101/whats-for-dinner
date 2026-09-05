<?php

namespace Tests\Feature;

use App\Enums\CategoryTag;
use App\Enums\IngredientsStatus;
use App\Enums\ProteinType;
use App\Enums\RecipeSource;
use App\Models\HouseholdSetting;
use App\Models\Recipe;
use App\Models\User;
use App\Services\Discovery\DiscoveredRecipe;
use App\Services\Discovery\DiscoveredRecipeImporter;
use App\Services\Discovery\RecipeSearchQuery;
use App\Services\Discovery\SerpApiRecipeSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Try something new" — recipes from outside the household's own library.
 */
class DiscoverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->user = User::factory()->create();
        config()->set('services.serpapi.key', 'test-key');
        Cache::flush();
    }

    private function serpResponse(array $recipes): array
    {
        return ['recipes_results' => $recipes];
    }

    private function oneResult(): array
    {
        return [
            'title' => 'Best Beef Tacos Ever | A Food Blog',
            'link' => 'https://example.com/beef-tacos/?utm_source=google',
            'source' => 'A Food Blog',
            'thumbnail' => 'https://example.com/taco.jpg',
            'rating' => 4.8,
            'reviews' => 214,
            'total_time' => '30 min',
            'ingredients' => ['Ground beef', 'Tortillas', 'Cheddar cheese'],
        ];
    }

    // --------------------------------------------------------- query building

    /**
     * A search engine has no structured cuisine or diet parameters, so the
     * filters have to become words in the phrase.
     */
    public function test_filters_are_folded_into_the_search_phrase(): void
    {
        $query = new RecipeSearchQuery(
            text: 'tacos',
            protein: ProteinType::Beef,
            tag: CategoryTag::Mexican,
            ketoOnly: true,
        );

        $phrase = $query->toSearchString();

        $this->assertStringContainsString('keto', $phrase);
        $this->assertStringContainsString('mexican', $phrase);
        $this->assertStringContainsString('beef', $phrase);
        $this->assertStringContainsString('tacos', $phrase);
        // Keeps Google on recipe pages rather than restaurants.
        $this->assertStringEndsWith('recipe', $phrase);
    }

    /** "Vegetarian" is a real search term; "no protein" and "other" are not. */
    public function test_vegetarian_is_searched_as_a_word(): void
    {
        $this->assertStringContainsString(
            'vegetarian',
            (new RecipeSearchQuery(protein: ProteinType::Vegetarian))->toSearchString(),
        );

        $this->assertStringNotContainsString(
            'no protein',
            (new RecipeSearchQuery(protein: ProteinType::None))->toSearchString(),
        );
    }

    public function test_an_empty_query_is_not_searched(): void
    {
        Http::fake();

        $this->assertTrue((new RecipeSearchQuery)->isEmpty());
        $this->assertCount(0, (new SerpApiRecipeSearch)->search(new RecipeSearchQuery));

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- searching

    public function test_it_reads_the_recipe_block(): void
    {
        Http::fake(['serpapi.com/*' => Http::response($this->serpResponse([$this->oneResult()]))]);

        $results = (new SerpApiRecipeSearch)->search(new RecipeSearchQuery(text: 'tacos'));

        $this->assertCount(1, $results);
        $first = $results->first();
        $this->assertSame('A Food Blog', $first->sourceName());
        $this->assertSame(4.8, $first->rating);
        // The site name is stripped off the end of the title.
        $this->assertSame('Best Beef Tacos Ever', $first->cleanTitle());
        $this->assertSame(['Ground beef', 'Tortillas', 'Cheddar cheese'], $first->ingredients);
    }

    /** Every call costs quota, so repeating a search must not spend another. */
    public function test_results_are_cached(): void
    {
        Http::fake(['serpapi.com/*' => Http::response($this->serpResponse([$this->oneResult()]))]);

        $search = new SerpApiRecipeSearch;
        $search->search(new RecipeSearchQuery(text: 'tacos'));
        $search->search(new RecipeSearchQuery(text: 'tacos'));

        Http::assertSentCount(1);
    }

    /** The same recipe is syndicated across several urls. */
    public function test_duplicate_urls_are_collapsed(): void
    {
        $a = $this->oneResult();
        $b = $this->oneResult();
        $b['link'] = 'https://example.com/beef-tacos?utm_source=elsewhere';

        Http::fake(['serpapi.com/*' => Http::response($this->serpResponse([$a, $b]))]);

        $this->assertCount(1, (new SerpApiRecipeSearch)->search(new RecipeSearchQuery(text: 'tacos')));
    }

    public function test_a_spent_quota_is_reported_plainly(): void
    {
        Http::fake(['serpapi.com/*' => Http::response([], 429)]);

        $this->actingAs($this->user)->get(route('discover', ['q' => 'tacos']))
            ->assertOk()
            ->assertSee('quota for this month has run out');
    }

    public function test_a_rejected_key_is_reported(): void
    {
        Http::fake(['serpapi.com/*' => Http::response([], 401)]);

        $this->actingAs($this->user)->get(route('discover', ['q' => 'tacos']))
            ->assertOk()
            ->assertSee('key was rejected');
    }

    // ------------------------------------------------------------- importing

    private function fakeSearchAndPage(): void
    {
        Http::fake([
            'serpapi.com/*' => Http::response($this->serpResponse([$this->oneResult()])),
            'example.com/beef-tacos*' => Http::response(<<<'HTML'
            <html><head><script type="application/ld+json">
            {"@type":"Recipe","name":"Beef Tacos","recipeYield":"4",
             "image":"https://example.com/taco.jpg",
             "recipeIngredient":["1 lb ground beef","8 corn tortillas","1 cup cheddar cheese"]}
            </script></head></html>
            HTML),
            '*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    /**
     * The point of importing rather than bookmarking: the full ingredient list
     * comes from the page, so the recipe works with everything downstream.
     */
    public function test_adding_a_result_imports_its_full_ingredients(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->fakeSearchAndPage();

        $this->actingAs($this->user)->post(route('discover.store'), [
            'title' => 'Best Beef Tacos Ever',
            'url' => 'https://example.com/beef-tacos/',
            'source' => 'A Food Blog',
        ])->assertRedirect();

        $recipe = Recipe::firstOrFail();
        $this->assertSame(RecipeSource::Discovered, $recipe->source);
        $this->assertSame('A Food Blog', $recipe->source_name);
        $this->assertSame(IngredientsStatus::AutoImported, $recipe->ingredients_status);
        // Quantities, which the search result did not carry.
        $this->assertCount(3, $recipe->ingredients);
        $this->assertNotNull($recipe->ingredients->firstWhere('name', 'Ground beef')->pivot->quantity_per_serving);
    }

    /** Tagged from what the search already told us, so it filters immediately. */
    public function test_an_imported_recipe_is_tagged_and_given_a_protein(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->fakeSearchAndPage();

        $recipe = (new DiscoveredRecipeImporter)->import(new DiscoveredRecipe(
            title: 'Best Beef Tacos Ever',
            url: 'https://example.com/beef-tacos/',
            source: 'A Food Blog',
            ingredients: ['Ground beef', 'Tortillas'],
        ));

        $this->assertSame(ProteinType::Beef, $recipe->protein_type);
        $this->assertTrue($recipe->category_tags->contains(CategoryTag::Mexican));
    }

    /** A broken page must still leave a usable recipe with its link. */
    public function test_a_page_that_cannot_be_read_still_yields_a_recipe(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $recipe = (new DiscoveredRecipeImporter)->import(new DiscoveredRecipe(
            title: 'Mystery Stew',
            url: 'https://example.com/mystery',
        ));

        $this->assertNotNull($recipe->id);
        $this->assertSame(IngredientsStatus::NotYetAdded, $recipe->ingredients_status);
        $this->assertSame(['https://example.com/mystery'], $recipe->recipe_links);
    }

    /**
     * Some large recipe sites answer 403 to anything automated. That will not
     * change on a retry, so the app says so rather than pointing at a button
     * that cannot work.
     */
    public function test_a_site_that_blocks_readers_is_reported_as_such(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]);

        $this->actingAs($this->user)->post(route('discover.store'), [
            'title' => 'Old School Beef Tacos',
            'url' => 'https://blocked.example.com/tacos',
        ])->assertRedirect();

        $this->assertStringContainsString(
            'blocks automated readers',
            (string) session('status'),
        );
    }

    /** A page that merely had nothing useful gets the ordinary message. */
    public function test_an_unreadable_page_is_not_reported_as_blocked(): void
    {
        Http::fake(['*' => Http::response('<html><body>nothing here</body></html>')]);

        $this->actingAs($this->user)->post(route('discover.store'), [
            'title' => 'Mystery Stew',
            'url' => 'https://example.com/mystery',
        ])->assertRedirect();

        $this->assertStringNotContainsString('blocks automated readers', (string) session('status'));
    }

    /** Adding the same result twice must not make a second copy. */
    public function test_an_already_imported_recipe_is_recognised(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $discovered = new DiscoveredRecipe(title: 'Beef Tacos', url: 'https://example.com/beef-tacos/');
        $importer = new DiscoveredRecipeImporter;

        $first = $importer->import($discovered);

        // Tracking parameters differ between searches for the same page.
        $again = new DiscoveredRecipe(title: 'Beef Tacos', url: 'https://example.com/beef-tacos?utm_source=x');

        $this->assertNotNull($importer->existing($again));
        $this->assertSame($first->id, $importer->import($again)->id);
        $this->assertSame(1, Recipe::count());
    }

    /** Recipe names are unique in the library. */
    public function test_a_colliding_title_is_given_a_suffix(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Recipe::create(['name' => 'Beef Tacos']);

        $recipe = (new DiscoveredRecipeImporter)->import(
            new DiscoveredRecipe(title: 'Beef Tacos', url: 'https://example.com/other-tacos'),
        );

        $this->assertSame('Beef Tacos (2)', $recipe->name);
        $this->assertSame(2, Recipe::count());
    }

    /** Imported recipes are ordinary recipes: they rank like everything else. */
    public function test_an_imported_recipe_joins_the_suggestion_engine(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $recipe = (new DiscoveredRecipeImporter)->import(
            new DiscoveredRecipe(title: 'Beef Tacos', url: 'https://example.com/beef-tacos'),
        );

        $ranked = (new \App\Services\RecipeSuggestionRanker)
            ->for(\Illuminate\Support\Carbon::parse('2026-09-09'));

        $this->assertTrue($ranked->contains(fn ($s) => $s->recipe->id === $recipe->id));
    }

    // ------------------------------------------------------------- the screens

    public function test_the_recipe_browser_offers_the_way_out(): void
    {
        $this->actingAs($this->user)->get(route('recipes'))
            ->assertOk()
            ->assertSee('Try Something New')
            ->assertSee(route('discover'), false);
    }

    /** Offered while filling a slot too, carrying the slot along. */
    public function test_the_dinner_picker_offers_it_and_comes_back(): void
    {
        $response = $this->actingAs($this->user)->get(route('plan.picker', [
            'date' => '2026-09-09', 'slot' => 'dinner', 'primary' => 1,
        ]))->assertOk();

        $response->assertSee('Try Something New');
        $response->assertSee('return_to', false);
    }

    public function test_adding_from_a_slot_returns_to_that_slot(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->actingAs($this->user)->post(route('discover.store'), [
            'title' => 'Beef Tacos',
            'url' => 'https://example.com/beef-tacos',
            'return_to' => '/plan/2026-09-09/dinner/add?primary=1',
        ])->assertRedirect('/plan/2026-09-09/dinner/add?primary=1');
    }

    /** An open redirect would be a real hole; only plan paths are honoured. */
    public function test_the_return_path_cannot_be_used_to_redirect_elsewhere(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->actingAs($this->user)->post(route('discover.store'), [
            'title' => 'Beef Tacos',
            'url' => 'https://example.com/beef-tacos',
            'return_to' => 'https://evil.example.com/phish',
        ])->assertRedirect(route('recipes.show', Recipe::firstOrFail()));
    }

    public function test_the_attribution_is_shown_on_the_recipe(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $recipe = (new DiscoveredRecipeImporter)->import(
            new DiscoveredRecipe(title: 'Beef Tacos', url: 'https://example.com/beef-tacos', source: 'A Food Blog'),
        );

        $this->actingAs($this->user)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('A Food Blog');
    }

    /** The household's keto setting carries into the search by default. */
    public function test_the_diet_setting_is_applied_without_restating_it(): void
    {
        HouseholdSetting::current()->update(['diet_mode' => 'keto']);
        Http::fake(['serpapi.com/*' => Http::response($this->serpResponse([]))]);

        $this->actingAs($this->user)->get(route('discover', ['q' => 'tacos']))->assertOk();

        Http::assertSent(fn ($request) => str_contains($request['q'] ?? '', 'keto'));
    }

    public function test_discovery_requires_authentication(): void
    {
        $this->get(route('discover'))->assertRedirect('/login');
        $this->post(route('discover.store'), ['title' => 'x', 'url' => 'https://example.com'])
            ->assertRedirect('/login');
    }
}
