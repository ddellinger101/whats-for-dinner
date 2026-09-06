<?php

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Services\MealPlanner;
use App\Services\Scraping\RecipeDetailImporter;
use App\Services\Scraping\RecipeScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 5 asks the What's For Dinner screen to show "instructions/link". Only
 * the link was built, so cooking meant leaving the app — which is most of what
 * that screen exists to avoid.
 */
class RecipeInstructionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->user = User::factory()->create();
    }

    /**
     * @return list<string>
     */
    private function stepsFrom(string $instructionsJson): array
    {
        $html = <<<HTML
        <html><head><script type="application/ld+json">
        {"@type":"Recipe","name":"Test","recipeYield":"4",
         "recipeIngredient":["1 lb ground beef"],
         "recipeInstructions":{$instructionsJson}}
        </script></head></html>
        HTML;

        return (new RecipeScraper)->extract($html, 'https://example.com/r')?->steps ?? [];
    }

    // ------------------------------------------------- the schema.org shapes

    /** The simplest shape: a plain list of strings. */
    public function test_it_reads_a_list_of_strings(): void
    {
        $steps = $this->stepsFrom('["Brown the beef.","Add the onion.","Simmer for 10 minutes."]');

        $this->assertSame(['Brown the beef.', 'Add the onion.', 'Simmer for 10 minutes.'], $steps);
    }

    /** The most common shape on real sites. */
    public function test_it_reads_how_to_step_objects(): void
    {
        $steps = $this->stepsFrom('[
            {"@type":"HowToStep","text":"Brown the beef."},
            {"@type":"HowToStep","text":"Add the onion."}
        ]');

        $this->assertSame(['Brown the beef.', 'Add the onion.'], $steps);
    }

    /**
     * Sections are flattened. Their names read nicely but would break a
     * numbered list you are trying to keep your place in.
     */
    public function test_it_flattens_how_to_sections(): void
    {
        $steps = $this->stepsFrom('[
            {"@type":"HowToSection","name":"For the sauce","itemListElement":[
                {"@type":"HowToStep","text":"Warm the cream."},
                {"@type":"HowToStep","text":"Whisk in the cheese."}
            ]},
            {"@type":"HowToSection","name":"To finish","itemListElement":[
                {"@type":"HowToStep","text":"Toss with the pasta."}
            ]}
        ]');

        $this->assertSame(['Warm the cream.', 'Whisk in the cheese.', 'Toss with the pasta.'], $steps);
    }

    public function test_it_strips_markup_from_steps(): void
    {
        $steps = $this->stepsFrom('["<p>Brown the <strong>beef</strong>.</p>","Add &amp; stir the onion."]');

        $this->assertSame(['Brown the beef.', 'Add & stir the onion.'], $steps);
    }

    /** Some sites write the whole method as one blob with line breaks. */
    public function test_it_splits_a_blob_on_line_breaks(): void
    {
        $steps = $this->stepsFrom('"Brown the beef.\nAdd the onion.\nSimmer for 10 minutes."');

        $this->assertCount(3, $steps);
        $this->assertSame('Add the onion.', $steps[1]);
    }

    /** Others number them inline with no line breaks at all. */
    public function test_it_splits_a_blob_on_numbered_markers(): void
    {
        $steps = $this->stepsFrom('"1. Brown the beef. 2. Add the onion. 3. Simmer for 10 minutes."');

        $this->assertCount(3, $steps);
        $this->assertSame('Brown the beef.', $steps[0]);
        // The typed numbering is dropped; the list supplies its own.
        $this->assertStringStartsNotWith('2.', $steps[1]);
    }

    /**
     * Sentences are deliberately not split on. "Bake at 350 degrees F. for 20
     * minutes" is one instruction, and breaking it in half mid-temperature
     * would be worse than leaving it long.
     */
    public function test_it_does_not_split_a_sentence_at_an_abbreviation(): void
    {
        $steps = $this->stepsFrom('"Bake at 350 degrees F. for 20 minutes, then rest."');

        $this->assertCount(1, $steps);
    }

    public function test_a_recipe_with_no_instructions_is_left_empty(): void
    {
        $this->assertSame([], $this->stepsFrom('null'));
    }

    // ------------------------------------------------------------ importing

    private function fakePage(string $instructions = '["Brown the beef.","Add the onion."]'): void
    {
        Storage::fake('public');
        Http::fake([
            'example.com/recipe' => Http::response(<<<HTML
            <html><head><script type="application/ld+json">
            {"@type":"Recipe","name":"Chilli","recipeYield":"4",
             "recipeIngredient":["1 lb ground beef","1 onion"],
             "recipeInstructions":{$instructions}}
            </script></head></html>
            HTML),
            '*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_importing_saves_the_method(): void
    {
        $this->fakePage();

        $recipe = Recipe::create(['name' => 'Chilli', 'recipe_links' => ['https://example.com/recipe']]);
        (new RecipeDetailImporter)->import($recipe);

        $this->assertTrue($recipe->fresh()->hasInstructions());
        $this->assertSame(['Brown the beef.', 'Add the onion.'], $recipe->fresh()->instructions);
    }

    /** A method typed by hand is worth more than whatever the page says today. */
    public function test_an_existing_method_is_never_overwritten(): void
    {
        $this->fakePage();

        $recipe = Recipe::create([
            'name' => 'Chilli',
            'recipe_links' => ['https://example.com/recipe'],
            'instructions' => ['My own way of doing it.'],
        ]);

        (new RecipeDetailImporter)->import($recipe);

        $this->assertSame(['My own way of doing it.'], $recipe->fresh()->instructions);
    }

    /**
     * Re-reading a page for its method must not quietly undo ingredients
     * someone entered by hand, since a scrape replaces them wholesale.
     */
    public function test_rescraping_for_a_method_keeps_hand_entered_ingredients(): void
    {
        $this->fakePage();

        $recipe = Recipe::create([
            'name' => 'Chilli',
            'recipe_links' => ['https://example.com/recipe'],
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        $mine = Ingredient::create([
            'name' => 'My secret spice',
            'category' => IngredientCategory::PantryDry,
            'shelf_life_days' => 365,
        ]);
        $recipe->ingredients()->attach($mine->id, [
            'id' => (string) Str::uuid(), 'quantity_per_serving' => 1, 'unit' => 'tsp',
        ]);

        (new RecipeDetailImporter)->import($recipe);

        $recipe->refresh();
        // The method arrived, the ingredients were left alone.
        $this->assertTrue($recipe->hasInstructions());
        $this->assertSame(['My secret spice'], $recipe->ingredients->pluck('name')->all());
        $this->assertSame(IngredientsStatus::ManuallyEntered, $recipe->ingredients_status);
    }

    /** A page offering only a method is still worth reading. */
    public function test_a_page_with_only_a_method_still_counts_as_a_success(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(<<<'HTML'
        <html><head><script type="application/ld+json">
        {"@type":"Recipe","name":"Chilli","recipeInstructions":["Brown the beef."]}
        </script></head></html>
        HTML)]);

        $recipe = Recipe::create(['name' => 'Chilli', 'recipe_links' => ['https://example.com/recipe']]);

        $this->assertTrue((new RecipeDetailImporter)->import($recipe));
        $this->assertTrue($recipe->fresh()->hasInstructions());
    }

    /** The archive was scraped before instructions existed. */
    public function test_the_rescrape_targets_recipes_missing_a_method(): void
    {
        $this->fakePage();

        $done = Recipe::create([
            'name' => 'Already Done',
            'recipe_links' => ['https://example.com/recipe'],
            'ingredients_status' => IngredientsStatus::AutoImported,
            'instructions' => ['Step one.'],
        ]);
        $needs = Recipe::create([
            'name' => 'Needs A Method',
            'recipe_links' => ['https://example.com/recipe'],
            'ingredients_status' => IngredientsStatus::AutoImported,
        ]);

        $this->artisan('recipes:scrape --missing-instructions')->assertSuccessful();

        $this->assertSame(['Step one.'], $done->fresh()->instructions);
        $this->assertSame(['Brown the beef.', 'Add the onion.'], $needs->fresh()->instructions);
    }

    // ------------------------------------------------------------- the screens

    /** The whole point: readable at the stove without leaving the app. */
    public function test_the_method_is_shown_on_tonights_meal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-09'));

        $recipe = Recipe::create([
            'name' => 'Chilli',
            'instructions' => ['Brown the beef.', 'Add the onion.'],
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);
        (new MealPlanner)->setPrimaryRecipe(Carbon::parse('2026-09-09'), MealSlot::Dinner, $recipe);

        $this->actingAs($this->user)->get(route('tonight'))
            ->assertOk()
            ->assertSee('Method')
            ->assertSeeInOrder(['Brown the beef.', 'Add the onion.']);

        Carbon::setTestNow();
    }

    public function test_a_method_can_be_typed_by_hand(): void
    {
        $recipe = Recipe::create(['name' => 'Chilli']);

        $this->actingAs($this->user)
            ->post(route('recipes.instructions', $recipe), [
                // Numbering typed by hand is dropped; the list supplies its own.
                'instructions' => "1. Brown the beef.\n2) Add the onion.\n\nSimmer.",
            ])
            ->assertRedirect();

        $this->assertSame(
            ['Brown the beef.', 'Add the onion.', 'Simmer.'],
            $recipe->fresh()->instructions,
        );
    }

    public function test_a_method_can_be_cleared(): void
    {
        $recipe = Recipe::create(['name' => 'Chilli', 'instructions' => ['Something.']]);

        $this->actingAs($this->user)->post(route('recipes.instructions', $recipe), ['instructions' => '']);

        $this->assertFalse($recipe->fresh()->hasInstructions());
    }

    public function test_a_method_can_be_set_when_creating_a_recipe(): void
    {
        $this->actingAs($this->user)->post(route('recipes.store'), [
            'name' => 'Homemade Pizza',
            'protein_type' => 'vegetarian',
            'meal_type' => 'dinner',
            'base_servings' => 4,
            'instructions' => "Make the dough.\nTop it.\nBake at 250 degrees C. for 10 minutes.",
        ])->assertRedirect();

        $recipe = Recipe::where('name', 'Homemade Pizza')->firstOrFail();
        $this->assertCount(3, $recipe->instructions);
        // Not split at the abbreviation.
        $this->assertSame('Bake at 250 degrees C. for 10 minutes.', $recipe->instructions[2]);
    }

    public function test_the_recipe_screen_offers_the_method_editor(): void
    {
        $recipe = Recipe::create(['name' => 'Chilli']);

        $this->actingAs($this->user)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Method')
            ->assertSee('Add the method');
    }

    public function test_editing_a_method_requires_authentication(): void
    {
        $recipe = Recipe::create(['name' => 'Chilli']);

        $this->post(route('recipes.instructions', $recipe), ['instructions' => 'x'])
            ->assertRedirect('/login');
    }
}
