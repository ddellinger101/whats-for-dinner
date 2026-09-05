<?php

namespace Tests\Feature;

use App\Enums\ImageStatus;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Jobs\ImportRecipeDetails;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\MealPlanner;
use App\Services\Scraping\RecipeDetailImporter;
use App\Services\Scraping\RecipeScraper;
use App\Support\IngredientCategoryGuesser;
use App\Support\IngredientLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipeScrapingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
    }

    // ------------------------------------------------------- line parsing

    public static function ingredientLines(): array
    {
        return [
            'whole number and unit' => ['2 cups flour', 2.0, 'cup', 'Flour'],
            'vulgar fraction' => ['½ cup sour cream', 0.5, 'cup', 'Sour cream'],
            'mixed number' => ['2 1/2 cups milk', 2.5, 'cup', 'Milk'],
            'plain fraction' => ['1/4 tsp salt', 0.25, 'tsp', 'Salt'],
            'decimal' => ['1.5 lb ground beef', 1.5, 'lb', 'Ground beef'],
            'metric aside removed' => ['2 cups (240g) flour', 2.0, 'cup', 'Flour'],
            'prep note after comma' => ['1 onion, finely chopped', 1.0, null, 'Onion'],
            'no quantity' => ['Salt and pepper to taste', null, null, 'Salt and pepper'],
            'unit abbreviation' => ['3 tbsp. olive oil', 3.0, 'tbsp', 'Olive oil'],
            'range takes the low end' => ['2-3 cloves garlic', 2.0, 'clove', 'Garlic'],
            'inline prep word' => ['1 cup finely grated parmesan', 1.0, 'cup', 'Parmesan'],
            'no unit, countable' => ['4 boneless chicken thighs', 4.0, null, 'Boneless chicken thighs'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ingredientLines')]
    public function test_it_parses_ingredient_lines(string $line, ?float $qty, ?string $unit, string $name): void
    {
        $parsed = IngredientLine::parse($line);

        $this->assertSame($qty, $parsed->quantity, "quantity for: {$line}");
        $this->assertSame($unit, $parsed->unit, "unit for: {$line}");
        $this->assertSame($name, $parsed->name, "name for: {$line}");
    }

    /** A line that parses to nothing must keep its original text. */
    public function test_an_unparseable_line_falls_back_to_the_raw_text(): void
    {
        $parsed = IngredientLine::parse('1 cup');

        $this->assertSame('1 cup', $parsed->name);
    }

    // --------------------------------------------------------- categories

    public function test_it_guesses_ingredient_categories(): void
    {
        $guesser = new IngredientCategoryGuesser;

        $this->assertSame(IngredientCategory::Protein, $guesser->guess('Ground beef'));
        $this->assertSame(IngredientCategory::Dairy, $guesser->guess('Sour cream'));
        $this->assertSame(IngredientCategory::Produce, $guesser->guess('Yellow onion'));
        $this->assertSame(IngredientCategory::PantryDry, $guesser->guess('All-purpose flour'));
        $this->assertSame(IngredientCategory::JarredCanned, $guesser->guess('Canned black beans'));
        $this->assertSame(IngredientCategory::Condiment, $guesser->guess('Olive oil'));
        $this->assertSame(IngredientCategory::Frozen, $guesser->guess('Frozen peas'));
    }

    /**
     * An unrecognised ingredient must still take part in use-by tracking, or it
     * silently opts out of the feature the app exists for.
     */
    public function test_unknown_ingredients_default_to_a_perishable_category(): void
    {
        $category = (new IngredientCategoryGuesser)->guess('Sazon Goya packet');

        $this->assertTrue($category->isPerishable());
    }

    // ------------------------------------------------------------ scraping

    private function recipePageHtml(): string
    {
        return <<<'HTML'
        <html><head>
        <meta property="og:image" content="https://example.com/fallback.jpg">
        <script type="application/ld+json">
        {"@context":"https://schema.org","@graph":[
          {"@type":"WebSite","name":"A Food Blog"},
          {"@type":["Recipe"],"name":"Weeknight Chilli",
           "image":{"@type":"ImageObject","url":"https://example.com/chilli.jpg"},
           "recipeYield":"6 servings",
           "recipeIngredient":["1 lb ground beef","2 cups (480ml) beef broth","1 onion, diced","1/2 tsp salt"]}
        ]}
        </script></head><body></body></html>
        HTML;
    }

    /** Most recipe blogs publish JSON-LD, often nested in an @graph. */
    public function test_it_extracts_a_recipe_from_nested_json_ld(): void
    {
        $scraped = (new RecipeScraper)->extract($this->recipePageHtml(), 'https://example.com/chilli');

        $this->assertNotNull($scraped);
        $this->assertSame('Weeknight Chilli', $scraped->title);
        $this->assertSame('https://example.com/chilli.jpg', $scraped->imageUrl);
        $this->assertSame(6, $scraped->servings);
        $this->assertCount(4, $scraped->ingredientLines);
    }

    /** With no Recipe block, og:image alone is still worth keeping. */
    public function test_it_falls_back_to_the_open_graph_image(): void
    {
        $html = '<html><head><meta property="og:image" content="/img/dish.png"></head></html>';

        $scraped = (new RecipeScraper)->extract($html, 'https://example.com/some/dish');

        $this->assertNotNull($scraped);
        $this->assertFalse($scraped->hasIngredients());
        // Root-relative URLs are resolved against the page.
        $this->assertSame('https://example.com/img/dish.png', $scraped->imageUrl);
    }

    public function test_a_page_with_nothing_useful_returns_null(): void
    {
        $this->assertNull((new RecipeScraper)->extract('<html><body>Hello</body></html>', 'https://example.com'));
    }

    /** Spec 4.7 expects these to fail, so they are not even requested. */
    public function test_hopeless_hosts_are_skipped_without_a_request(): void
    {
        $scraper = new RecipeScraper;

        $this->assertFalse($scraper->isWorthTrying('https://pin.it/7IYUos75D'));
        $this->assertFalse($scraper->isWorthTrying('https://www.instagram.com/p/abc/'));
        $this->assertFalse($scraper->isWorthTrying('https://youtu.be/abc'));
        $this->assertTrue($scraper->isWorthTrying('https://thecozycook.com/chicken-enchiladas/'));
    }

    // ------------------------------------------------------------ importing

    public function test_it_imports_ingredients_scaled_to_per_serving(): void
    {
        Storage::fake('public');

        Http::fake([
            'example.com/chilli' => Http::response($this->recipePageHtml()),
            'example.com/chilli.jpg' => Http::response('binary-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $recipe = Recipe::create([
            'name' => 'Chilli',
            'recipe_links' => ['https://example.com/chilli'],
            'base_servings' => 4,
        ]);

        $this->assertTrue((new RecipeDetailImporter)->import($recipe));

        $recipe->refresh();
        $this->assertSame(IngredientsStatus::AutoImported, $recipe->ingredients_status);
        // The page's own yield replaces the placeholder.
        $this->assertSame(6, $recipe->base_servings);
        $this->assertCount(4, $recipe->ingredients);

        $beef = $recipe->ingredients->firstWhere('name', 'Ground beef');
        $this->assertNotNull($beef);
        // 1 lb across 6 servings.
        $this->assertEqualsWithDelta(1 / 6, (float) $beef->pivot->quantity_per_serving, 0.0001);
        $this->assertSame('lb', $beef->pivot->unit);
        $this->assertSame(IngredientCategory::Protein, $beef->category);
    }

    public function test_it_stores_the_scraped_image(): void
    {
        Storage::fake('public');

        Http::fake([
            'example.com/chilli' => Http::response($this->recipePageHtml()),
            'example.com/chilli.jpg' => Http::response('binary-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $recipe = Recipe::create(['name' => 'Chilli', 'recipe_links' => ['https://example.com/chilli']]);
        (new RecipeDetailImporter)->import($recipe);

        $recipe->refresh();
        $this->assertSame(ImageStatus::Scraped, $recipe->image_status);
        Storage::disk('public')->assertExists($recipe->image_path);
    }

    /** A photo taken in the kitchen outranks anything scraped later. */
    public function test_it_never_overwrites_a_user_photo(): void
    {
        Storage::fake('public');

        Http::fake([
            'example.com/chilli' => Http::response($this->recipePageHtml()),
            'example.com/chilli.jpg' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $recipe = Recipe::create([
            'name' => 'Chilli',
            'recipe_links' => ['https://example.com/chilli'],
            'image_path' => 'recipes/mine.jpg',
            'image_status' => ImageStatus::Uploaded,
        ]);

        (new RecipeDetailImporter)->import($recipe);

        $this->assertSame('recipes/mine.jpg', $recipe->fresh()->image_path);
        $this->assertSame(ImageStatus::Uploaded, $recipe->fresh()->image_status);
    }

    /** Spec 4.7: links are tried in order until one works. */
    public function test_it_tries_each_link_until_one_yields_data(): void
    {
        Storage::fake('public');

        Http::fake([
            'deadsite.com/*' => Http::response('', 404),
            'example.com/chilli' => Http::response($this->recipePageHtml()),
            '*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $recipe = Recipe::create([
            'name' => 'Chilli',
            // The Pinterest link should be skipped without a request at all.
            'recipe_links' => ['https://pin.it/abc', 'https://deadsite.com/gone', 'https://example.com/chilli'],
        ]);

        $this->assertTrue((new RecipeDetailImporter)->import($recipe));
        $this->assertCount(4, $recipe->fresh()->ingredients);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'pin.it'));
    }

    public function test_a_recipe_with_no_links_is_left_alone(): void
    {
        $recipe = Recipe::create(['name' => 'Family Secret', 'recipe_links' => []]);

        $this->assertFalse((new RecipeDetailImporter)->import($recipe));
        $this->assertSame(IngredientsStatus::NotYetAdded, $recipe->fresh()->ingredients_status);
    }

    /** Reusing an existing ingredient keeps the master list from splitting. */
    public function test_it_reuses_existing_ingredients_case_insensitively(): void
    {
        Storage::fake('public');
        Http::fake([
            'example.com/chilli' => Http::response($this->recipePageHtml()),
            '*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $existing = Ingredient::create([
            'name' => 'ground beef',
            'category' => IngredientCategory::Protein,
            'shelf_life_days' => 3,
        ]);

        $recipe = Recipe::create(['name' => 'Chilli', 'recipe_links' => ['https://example.com/chilli']]);
        (new RecipeDetailImporter)->import($recipe);

        $this->assertSame(1, Ingredient::whereRaw('LOWER(name) = ?', ['ground beef'])->count());
        $this->assertTrue($recipe->fresh()->ingredients->contains('id', $existing->id));
    }

    // ------------------------------------------------------------ wiring

    /** Spec 4.7: first selection as a main triggers the import. */
    public function test_choosing_a_main_queues_an_import_when_ingredients_are_missing(): void
    {
        Queue::fake();

        $recipe = Recipe::create([
            'name' => 'Chilli',
            'recipe_links' => ['https://example.com/chilli'],
            'ingredients_status' => IngredientsStatus::NotYetAdded,
        ]);

        (new MealPlanner)->setPrimaryRecipe(Carbon::parse('2026-09-09'), MealSlot::Dinner, $recipe);

        Queue::assertPushed(ImportRecipeDetails::class,
            fn (ImportRecipeDetails $job) => $job->recipeId === $recipe->id);
    }

    public function test_no_import_is_queued_when_ingredients_already_exist(): void
    {
        Queue::fake();

        $recipe = Recipe::create([
            'name' => 'Chilli',
            'recipe_links' => ['https://example.com/chilli'],
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        (new MealPlanner)->setPrimaryRecipe(Carbon::parse('2026-09-09'), MealSlot::Dinner, $recipe);

        Queue::assertNotPushed(ImportRecipeDetails::class);
    }

    /** Manual entry must not be clobbered by a job that was already queued. */
    public function test_the_job_defers_to_manually_entered_ingredients(): void
    {
        Http::fake();

        $recipe = Recipe::create([
            'name' => 'Chilli',
            'recipe_links' => ['https://example.com/chilli'],
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        (new ImportRecipeDetails($recipe->id))->handle(new RecipeDetailImporter);

        Http::assertNothingSent();
    }
}
