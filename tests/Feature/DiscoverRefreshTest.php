<?php

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use App\Services\Discovery\RecipeSearchQuery;
use App\Services\InventoryService;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Finding something new to cook.
 *
 * Google's recipe block holds three results and offers no way to page through
 * it, so "show me three others" has to mean asking the same question a
 * slightly different way.
 */
class DiscoverRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
        config()->set('services.serpapi.key', 'test-key');
    }

    /** @param  list<string>  $titles */
    private function fakeBlock(array $titles): array
    {
        return ['recipes_results' => array_map(fn (string $t) => [
            'title' => $t,
            'link' => 'https://example.com/'.str($t)->slug(),
        ], $titles)];
    }

    // ------------------------------------------------------------ variation

    /** A variation is the same request, asked differently. */
    public function test_each_variation_asks_a_different_question(): void
    {
        $base = new RecipeSearchQuery(text: 'chicken');

        $asked = collect(range(0, RecipeSearchQuery::variationCount() - 1))
            ->map(fn (int $v) => (new RecipeSearchQuery(text: 'chicken', variation: $v))->toSearchString());

        $this->assertSame(
            $asked->count(),
            $asked->unique()->count(),
            'every variation should be a distinct search',
        );

        // And each caches on its own key, so cycling back costs no quota.
        $this->assertNotSame($base->cacheKey(), $base->next()->cacheKey());

        // Still the same request underneath.
        foreach ($asked as $phrase) {
            $this->assertStringContainsString('chicken', $phrase);
            $this->assertStringContainsString('recipe', $phrase);
        }
    }

    /** Three was not a choice the app made — it was all there was. */
    public function test_the_page_shows_more_than_one_block(): void
    {
        Http::fakeSequence()
            ->push($this->fakeBlock(['Roast chicken', 'Chicken pie', 'Chicken soup']))
            ->push($this->fakeBlock(['Easy chicken curry', 'Chicken tacos', 'Chicken salad']));

        $results = $this->actingAs($this->user)
            ->get(route('discover', ['q' => 'chicken']))
            ->assertOk()
            ->viewData('results');

        $this->assertCount(6, $results);
    }

    /** The same recipe turning up in both askings is shown once. */
    public function test_a_repeat_across_variations_is_not_shown_twice(): void
    {
        Http::fakeSequence()
            ->push($this->fakeBlock(['Roast chicken', 'Chicken pie']))
            ->push($this->fakeBlock(['Roast chicken', 'Chicken tacos']));

        $results = $this->actingAs($this->user)
            ->get(route('discover', ['q' => 'chicken']))
            ->assertOk()
            ->viewData('results');

        $this->assertCount(3, $results);
    }

    /** Refreshing moves on by two, since two are shown at a time. */
    public function test_the_refresh_link_skips_what_is_already_on_screen(): void
    {
        Http::fake(['*' => Http::response($this->fakeBlock(['Roast chicken']))]);

        $response = $this->actingAs($this->user)
            ->get(route('discover', ['q' => 'chicken', 'v' => 4]))
            ->assertOk();

        $this->assertSame(6, $response->viewData('nextVariation'));
        $response->assertSee('Show me others');
    }

    // -------------------------------------------------------- the pantry

    /** The reason to open this screen is usually "what can I make". */
    public function test_an_empty_search_starts_from_what_needs_using_up(): void
    {
        Http::fake(['*' => Http::response($this->fakeBlock(['Courgette soup']))]);

        $courgette = Ingredient::create([
            'name' => 'Courgettes',
            'category' => IngredientCategory::Produce,
            'shelf_life_days' => 2,
        ]);
        (new InventoryService)->add($courgette, 2, null);

        $response = $this->actingAs($this->user)->get(route('discover'))->assertOk();

        $this->assertTrue($response->viewData('usingPantry'));
        $this->assertSame('courgettes', $response->viewData('pantryTerms'));
        // Said out loud, rather than quietly searching for something nobody typed.
        $response->assertSee('needs using up');

        Http::assertSent(fn ($request) => str_contains($request['q'], 'courgettes'));
    }

    /** A search someone typed is not overruled by the fridge. */
    public function test_a_typed_search_is_left_alone(): void
    {
        Http::fake(['*' => Http::response($this->fakeBlock(['Lasagne']))]);

        $courgette = Ingredient::create([
            'name' => 'Courgettes',
            'category' => IngredientCategory::Produce,
            'shelf_life_days' => 2,
        ]);
        (new InventoryService)->add($courgette, 2, null);

        $response = $this->actingAs($this->user)
            ->get(route('discover', ['q' => 'lasagne']))
            ->assertOk();

        $this->assertFalse($response->viewData('usingPantry'));
        Http::assertSent(fn ($request) => ! str_contains($request['q'], 'courgettes'));
    }

    /** Nothing going off means nothing to suggest, not an empty search. */
    public function test_an_empty_pantry_asks_for_nothing(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user)->get(route('discover'))->assertOk();

        $this->assertFalse($response->viewData('usingPantry'));
        Http::assertNothingSent();
    }
}
