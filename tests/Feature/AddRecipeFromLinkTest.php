<?php

namespace Tests\Feature;

use App\Enums\CategoryTag;
use App\Enums\IngredientsStatus;
use App\Enums\ProteinType;
use App\Enums\RecipeSource;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Adding a recipe from nothing but its link.
 *
 * The commonest way a recipe arrives, and typing its title, ingredients,
 * method and tags back in by hand is exactly the chore the scraper exists to
 * avoid.
 */
class AddRecipeFromLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->user = User::factory()->create();
        Storage::fake('public');
    }

    private function fakeRecipePage(string $name = 'Ground Beef Tacos'): void
    {
        Http::fake([
            // A separate host for the image, or the page fake answers that
            // request too and hands back HTML where a JPEG was expected.
            'img.example.net/*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
            'example.com/*' => Http::response(<<<HTML
            <html><head><script type="application/ld+json">
            {"@type":"Recipe","name":"{$name}","recipeYield":"6",
             "image":"https://img.example.net/taco.jpg",
             "recipeIngredient":["1 lb ground beef","8 corn tortillas","1 cup cheddar cheese"],
             "recipeInstructions":[
                {"@type":"HowToStep","text":"Brown the beef."},
                {"@type":"HowToStep","text":"Warm the tortillas."}
             ]}
            </script></head></html>
            HTML),
            '*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    /** Everything the page publishes, from one field. */
    public function test_a_link_alone_produces_a_complete_recipe(): void
    {
        $this->fakeRecipePage();

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'https://example.com/ground-beef-tacos/'])
            ->assertRedirect();

        $recipe = Recipe::firstOrFail();

        $this->assertSame('Ground Beef Tacos', $recipe->name);
        $this->assertSame(6, $recipe->base_servings);
        $this->assertCount(3, $recipe->ingredients);
        $this->assertSame(['Brown the beef.', 'Warm the tortillas.'], $recipe->instructions);
        $this->assertNotNull($recipe->image_path);
        $this->assertSame(IngredientsStatus::AutoImported, $recipe->ingredients_status);
    }

    /** Filterable the moment it lands, without anyone tagging it. */
    public function test_it_is_tagged_and_given_a_protein(): void
    {
        $this->fakeRecipePage();

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'https://example.com/ground-beef-tacos/']);

        $recipe = Recipe::firstOrFail();
        $this->assertSame(ProteinType::Beef, $recipe->protein_type);
        $this->assertTrue($recipe->category_tags->contains(CategoryTag::Mexican));
    }

    /** Someone else's recipe, so the site is credited. */
    public function test_the_source_is_recorded_for_attribution(): void
    {
        $this->fakeRecipePage();

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'https://example.com/ground-beef-tacos/']);

        $recipe = Recipe::firstOrFail();
        $this->assertSame(RecipeSource::Discovered, $recipe->source);
        $this->assertTrue($recipe->source->needsAttribution());
        $this->assertSame('https://example.com/ground-beef-tacos/', $recipe->source_url);
        $this->assertSame(['https://example.com/ground-beef-tacos/'], $recipe->recipe_links);
    }

    /** A recipe slug is almost always the dish. */
    public function test_a_page_with_no_title_is_named_from_its_link(): void
    {
        Http::fake([
            'example.com/*' => Http::response(<<<'HTML'
            <html><head><script type="application/ld+json">
            {"@type":"Recipe","recipeIngredient":["1 lb ground beef"]}
            </script></head></html>
            HTML),
        ]);

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'https://example.com/best-ground-beef-tacos-recipe/']);

        // "recipe" is noise in a list of recipes, so it is trimmed.
        $this->assertSame('Best Ground Beef Tacos', Recipe::firstOrFail()->name);
    }

    /**
     * Nothing readable means no recipe. An empty one called "Untitled" would
     * look like it had worked.
     */
    public function test_an_unreadable_page_returns_to_the_form_with_the_link_kept(): void
    {
        Http::fake(['*' => Http::response('<html><body>nothing</body></html>')]);

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'https://example.com/mystery'])
            ->assertRedirect(route('recipes.create', ['url' => 'https://example.com/mystery']))
            ->assertSessionHasErrors('url');

        $this->assertSame(0, Recipe::count());
    }

    /** A site that refuses readers will refuse again, so say which it was. */
    public function test_a_blocking_site_is_named_as_the_reason(): void
    {
        Http::fake(['*' => Http::response('Forbidden', 403)]);

        // Followed through, so this asserts what the household actually reads
        // rather than the shape of the session bag.
        $this->actingAs($this->user)
            ->followingRedirects()
            ->post(route('recipes.from-link'), ['url' => 'https://blocked.example.com/tacos'])
            ->assertOk()
            ->assertSee('blocks automated readers');
    }

    /** The form keeps the link, so nothing has to be pasted twice. */
    public function test_the_form_prefills_the_link_that_failed(): void
    {
        $this->actingAs($this->user)
            ->get(route('recipes.create', ['url' => 'https://example.com/mystery']))
            ->assertOk()
            ->assertSee('https://example.com/mystery');
    }

    /** Adding the same link twice must not make a second copy. */
    public function test_a_link_already_in_the_library_returns_the_existing_recipe(): void
    {
        $this->fakeRecipePage();

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'https://example.com/ground-beef-tacos/']);

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'https://example.com/ground-beef-tacos?utm_source=x']);

        $this->assertSame(1, Recipe::count());
    }

    /** A page giving only half the story still says which half is missing. */
    public function test_a_partial_page_says_what_could_not_be_read(): void
    {
        Http::fake([
            'example.com/*' => Http::response(<<<'HTML'
            <html><head><script type="application/ld+json">
            {"@type":"Recipe","name":"Half A Recipe","recipeIngredient":["1 lb ground beef"]}
            </script></head></html>
            HTML),
        ]);

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'https://example.com/half']);

        $this->assertStringContainsString('method could not be read', (string) session('status'));
    }

    public function test_the_new_recipe_screen_offers_the_link_box(): void
    {
        $this->actingAs($this->user)->get(route('recipes.create'))
            ->assertOk()
            ->assertSee('Have a link?')
            ->assertSee('Or enter it yourself');
    }

    public function test_a_bad_url_is_rejected(): void
    {
        Http::fake();

        $this->actingAs($this->user)
            ->post(route('recipes.from-link'), ['url' => 'not-a-url'])
            ->assertSessionHasErrors('url');

        Http::assertNothingSent();
        $this->assertSame(0, Recipe::count());
    }

    public function test_adding_from_a_link_requires_authentication(): void
    {
        $this->post(route('recipes.from-link'), ['url' => 'https://example.com/x'])
            ->assertRedirect('/login');
    }
}
