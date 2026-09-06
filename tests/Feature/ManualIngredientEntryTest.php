<?php

namespace Tests\Feature;

use App\Enums\ImageStatus;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Spec 4.7's manual fallback. Over half the archive has no usable link, so
 * without this those recipes could never take part in use-by tracking or the
 * grocery list.
 */
class ManualIngredientEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
    }

    private function recipe(int $baseServings = 4): Recipe
    {
        return Recipe::create(['name' => 'Test Dish', 'base_servings' => $baseServings]);
    }

    /** Amounts are entered as the whole recipe, stored per serving (spec 4.3). */
    public function test_adding_an_ingredient_converts_the_amount_to_per_serving(): void
    {
        $recipe = $this->recipe(baseServings: 4);

        $this->actingAs($this->user)
            ->post(route('recipes.ingredients.store', $recipe), [
                'name' => 'Ground beef',
                'quantity' => 2,
                'unit' => 'lb',
            ])
            ->assertRedirect();

        $ingredient = $recipe->fresh()->ingredients->firstOrFail();
        $this->assertSame('Ground beef', $ingredient->name);
        // 2 lb across 4 servings.
        $this->assertEqualsWithDelta(0.5, (float) $ingredient->pivot->quantity_per_serving, 0.0001);
        $this->assertSame('lb', $ingredient->pivot->unit);
        $this->assertSame(IngredientCategory::Protein, $ingredient->category);
    }

    /** Manual entry outranks a scrape, so the status has to say so. */
    public function test_manual_entry_marks_the_recipe_as_manually_entered(): void
    {
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.ingredients.store', $recipe), ['name' => 'Salt']);

        $this->assertSame(IngredientsStatus::ManuallyEntered, $recipe->fresh()->ingredients_status);
    }

    /** The bulk path uses the same parser the scraper does. */
    public function test_pasting_a_list_parses_every_line(): void
    {
        $recipe = $this->recipe(baseServings: 4);

        $this->actingAs($this->user)
            ->post(route('recipes.ingredients.bulk', $recipe), [
                'lines' => "1 lb ground beef\n2 cups (480ml) beef broth\n1 onion, diced\n1/2 tsp salt\n\n",
            ])
            ->assertRedirect();

        $ingredients = $recipe->fresh()->ingredients;
        $this->assertCount(4, $ingredients);

        $this->assertSame(
            ['Beef broth', 'Ground beef', 'Onion', 'Salt'],
            $ingredients->pluck('name')->sort()->values()->all(),
        );

        $broth = $ingredients->firstWhere('name', 'Beef broth');
        $this->assertEqualsWithDelta(0.5, (float) $broth->pivot->quantity_per_serving, 0.0001);
        $this->assertSame('cup', $broth->pivot->unit);
        // A carton of stock is not raw beef.
        $this->assertSame(IngredientCategory::JarredCanned, $broth->category);
    }

    /**
     * The real paste that exposed this. Two faults together: the site typesets
     * fractions with U+2044, which the quantity parser did not know, and
     * ucfirst then mangled the first byte of that multibyte character —
     * producing invalid UTF-8 that the database reduced to nothing, collapsing
     * six ingredients into one blank row.
     */
    public function test_a_paste_using_typographic_fractions_is_read_correctly(): void
    {
        $recipe = $this->recipe(baseServings: 4);

        $this->actingAs($this->user)
            ->post(route('recipes.ingredients.bulk', $recipe), [
                'lines' => "1\u{2044}2 cup White wine\n\n1 cup Heavy cream\n\n"
                    ."1\u{2044}4 teaspoon Thyme dried\n\n1\u{2044}8 teaspoon Dill\n\n"
                    ."1\u{2044}2 teaspoon Red pepper flakes\n\n1\u{2044}2 cup Parsley rough chopped",
            ])
            ->assertRedirect();

        $ingredients = $recipe->fresh()->ingredients;

        $this->assertCount(6, $ingredients, 'all six lines should become ingredients');

        // Three of the six are spice-rack jars, so they resolve to the label on
        // the jar rather than to however this site happened to write them.
        $this->assertSame(
            ['Crushed red pepper', 'Dill', 'Heavy cream', 'Parsley flakes', 'Thyme leaves', 'White wine'],
            $ingredients->pluck('name')->sort()->values()->all(),
        );

        // Every name is valid UTF-8, none blank.
        foreach ($ingredients as $ingredient) {
            $this->assertNotSame('', trim($ingredient->name));
            $this->assertTrue(mb_check_encoding($ingredient->name, 'UTF-8'), $ingredient->name);
        }

        // Half a cup across four servings.
        $wine = $ingredients->firstWhere('name', 'White wine');
        $this->assertEqualsWithDelta(0.125, (float) $wine->pivot->quantity_per_serving, 0.0001);
        $this->assertSame('cup', $wine->pivot->unit);
    }

    /** A name that survives as multibyte must not be corrupted either. */
    public function test_a_non_ascii_ingredient_name_is_preserved(): void
    {
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.ingredients.bulk', $recipe), ['lines' => "2 Jalapeños\n1 cup Crème fraîche"]);

        $names = $recipe->fresh()->ingredients->pluck('name')->sort()->values()->all();

        foreach ($names as $name) {
            $this->assertTrue(mb_check_encoding($name, 'UTF-8'), $name);
        }

        $this->assertContains('Jalapeños', $names);
    }

    public function test_the_same_ingredient_is_not_added_twice(): void
    {
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.ingredients.bulk', $recipe), ['lines' => "1 onion\n2 onions"]);

        $this->assertCount(1, $recipe->fresh()->ingredients);
    }

    public function test_an_ingredient_can_be_removed(): void
    {
        $recipe = $this->recipe();
        $this->actingAs($this->user)
            ->post(route('recipes.ingredients.bulk', $recipe), ['lines' => "1 onion\n1 tsp salt"]);

        $onion = Ingredient::where('name', 'Onion')->firstOrFail();

        $this->actingAs($this->user)
            ->delete(route('recipes.ingredients.destroy', [$recipe, $onion]))
            ->assertRedirect();

        $this->assertCount(1, $recipe->fresh()->ingredients);
        // The ingredient itself survives for other recipes.
        $this->assertNotNull(Ingredient::find($onion->id));
    }

    /** Emptying the list must not leave the recipe looking complete. */
    public function test_removing_the_last_ingredient_reverts_the_status(): void
    {
        $recipe = $this->recipe();
        $this->actingAs($this->user)
            ->post(route('recipes.ingredients.store', $recipe), ['name' => 'Onion']);

        $this->assertSame(IngredientsStatus::ManuallyEntered, $recipe->fresh()->ingredients_status);

        $onion = Ingredient::where('name', 'Onion')->firstOrFail();
        $this->actingAs($this->user)->delete(route('recipes.ingredients.destroy', [$recipe, $onion]));

        $this->assertSame(IngredientsStatus::NotYetAdded, $recipe->fresh()->ingredients_status);
    }

    /**
     * Correcting the yield must not silently change how much food the recipe
     * makes — the amounts already entered were for the whole dish.
     */
    public function test_changing_the_yield_preserves_the_total_amounts(): void
    {
        $recipe = $this->recipe(baseServings: 4);
        $this->actingAs($this->user)->post(route('recipes.ingredients.store', $recipe), [
            'name' => 'Ground beef', 'quantity' => 2, 'unit' => 'lb',
        ]);

        $this->actingAs($this->user)
            ->post(route('recipes.servings', $recipe), ['base_servings' => 8])
            ->assertRedirect();

        $recipe->refresh();
        $this->assertSame(8, $recipe->base_servings);

        $pivot = $recipe->ingredients->firstOrFail()->pivot;
        // Still 2 lb in total, now split across 8.
        $this->assertEqualsWithDelta(0.25, (float) $pivot->quantity_per_serving, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $pivot->quantity_per_serving * 8, 0.0001);
    }

    // ------------------------------------------------------------- photos

    public function test_a_photo_can_be_uploaded(): void
    {
        Storage::fake('public');
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.photo.store', $recipe), [
                'photo' => UploadedFile::fake()->image('dinner.jpg', 800, 600),
            ])
            ->assertRedirect();

        $recipe->refresh();
        $this->assertSame(ImageStatus::Uploaded, $recipe->image_status);
        $this->assertNotNull($recipe->image_path);
        Storage::disk('public')->assertExists($recipe->image_path);
    }

    /** An uploaded photo is permanently protected from a later scrape. */
    public function test_an_uploaded_photo_blocks_scraped_replacement(): void
    {
        Storage::fake('public');
        $recipe = $this->recipe();

        $this->actingAs($this->user)->post(route('recipes.photo.store', $recipe), [
            'photo' => UploadedFile::fake()->image('dinner.jpg'),
        ]);

        $this->assertFalse($recipe->fresh()->canAcceptScrapedImage());
    }

    public function test_replacing_a_photo_removes_the_old_file(): void
    {
        Storage::fake('public');
        $recipe = $this->recipe();

        $this->actingAs($this->user)->post(route('recipes.photo.store', $recipe), [
            'photo' => UploadedFile::fake()->image('first.png'),
        ]);
        $first = $recipe->fresh()->image_path;

        $this->actingAs($this->user)->post(route('recipes.photo.store', $recipe), [
            'photo' => UploadedFile::fake()->image('second.jpg'),
        ]);
        $second = $recipe->fresh()->image_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_photo_can_be_removed(): void
    {
        Storage::fake('public');
        $recipe = $this->recipe();

        $this->actingAs($this->user)->post(route('recipes.photo.store', $recipe), [
            'photo' => UploadedFile::fake()->image('dinner.jpg'),
        ]);
        $path = $recipe->fresh()->image_path;

        $this->actingAs($this->user)
            ->delete(route('recipes.photo.destroy', $recipe))
            ->assertRedirect();

        $recipe->refresh();
        $this->assertNull($recipe->image_path);
        $this->assertSame(ImageStatus::None, $recipe->image_status);
        Storage::disk('public')->assertMissing($path);
    }

    /**
     * On a phone the single input carried capture="environment", which opens
     * the camera and hides the photo library entirely — so a picture already
     * saved to the device could not be used at all. Taking and choosing have
     * to be separate controls, and only one of them may carry capture.
     */
    public function test_the_page_offers_all_three_ways_to_add_a_photo(): void
    {
        $recipe = $this->recipe();

        $html = $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Take a photo', $html);
        $this->assertStringContainsString('Choose a file', $html);
        $this->assertStringContainsString('Paste an image link', $html);
        $this->assertStringContainsString(route('recipes.photo.url', $recipe), $html);
        $this->assertSame(1, substr_count($html, 'capture="environment"'));
    }

    /** The third way in, for pages the scraper is refused by. */
    public function test_a_photo_can_be_pasted_as_a_link(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('binary', 200, ['Content-Type' => 'image/webp'])]);
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.photo.url', $recipe), [
                'image_url' => 'https://example.com/photos/dinner.webp',
            ])
            ->assertSessionHasNoErrors();

        $recipe->refresh();
        // Counts as the household's own, so a later scrape leaves it alone.
        $this->assertSame(ImageStatus::Uploaded, $recipe->image_status);
        $this->assertSame('https://example.com/photos/dinner.webp', $recipe->image_source_url);
        Storage::disk('public')->assertExists($recipe->image_path);
    }

    /**
     * The likeliest mistake by far: copying the address of the page the
     * picture sits on rather than of the picture. Saying so beats a recipe
     * whose photo is silently a lump of HTML.
     */
    public function test_a_link_to_a_page_rather_than_a_picture_is_refused(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.photo.url', $recipe), [
                'image_url' => 'https://example.com/recipes/hawaiian-meatballs/',
            ])
            ->assertSessionHasErrors('image_url');

        $recipe->refresh();
        $this->assertNull($recipe->image_path);
        $this->assertSame(ImageStatus::None, $recipe->image_status);
    }

    /** A site that refuses the fetch says so rather than failing silently. */
    public function test_a_refused_image_link_reports_the_status(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('nope', 403)]);
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.photo.url', $recipe), [
                'image_url' => 'https://example.com/blocked.jpg',
            ])
            ->assertSessionHasErrors('image_url');

        $this->assertNull($recipe->fresh()->image_path);
    }

    /**
     * A write that fails must not leave the recipe claiming a photo. An
     * unwritable directory did exactly that in production: put() returned
     * false, the recipe was updated anyway, and the app served a broken image
     * with nothing logged anywhere.
     */
    public function test_a_failed_write_leaves_the_recipe_untouched(): void
    {
        Storage::fake('public');
        $recipe = $this->recipe();

        // Stands in for the unwritable directory.
        Storage::shouldReceive('disk')->with('public')->andReturn(
            tap(\Mockery::mock(Filesystem::class), function ($disk) {
                $disk->shouldReceive('put')->andReturn(false);
            }),
        );

        $this->actingAs($this->user)
            ->post(route('recipes.photo.store', $recipe), [
                'photo' => UploadedFile::fake()->image('dinner.jpg'),
            ])
            ->assertSessionHasErrors('photo');

        $recipe->refresh();
        $this->assertNull($recipe->image_path);
        $this->assertSame(ImageStatus::None, $recipe->image_status);
    }

    /** Rows recorded before that check still claim photos they never had. */
    public function test_dangling_image_paths_can_be_found_and_cleared(): void
    {
        Storage::fake('public');

        $good = $this->recipe();
        $this->actingAs($this->user)->post(route('recipes.photo.store', $good), [
            'photo' => UploadedFile::fake()->image('real.jpg'),
        ]);

        $broken = Recipe::create([
            'name' => 'Hawaiian Meatballs',
            'image_path' => 'recipes/never-written.webp',
            'image_status' => ImageStatus::Uploaded,
        ]);

        $this->artisan('recipes:check-images')->assertSuccessful();
        // Reports before it writes.
        $this->assertNotNull($broken->fresh()->image_path);

        $this->artisan('recipes:check-images --apply')->assertSuccessful();

        $this->assertNull($broken->fresh()->image_path);
        $this->assertSame(ImageStatus::None, $broken->fresh()->image_status);
        // The one that really exists is left alone.
        $this->assertNotNull($good->fresh()->image_path);
    }

    public function test_non_images_are_rejected(): void
    {
        Storage::fake('public');
        $recipe = $this->recipe();

        $this->actingAs($this->user)
            ->post(route('recipes.photo.store', $recipe), [
                'photo' => UploadedFile::fake()->create('recipe.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertNull($recipe->fresh()->image_path);
    }

    // --------------------------------------------------------------- access

    public function test_ingredient_editing_requires_authentication(): void
    {
        $recipe = $this->recipe();

        $this->post(route('recipes.ingredients.store', $recipe), ['name' => 'Onion'])
            ->assertRedirect('/login');

        $this->assertCount(0, $recipe->fresh()->ingredients);
    }

    /** The screen has to be reachable for any of this to be usable. */
    public function test_the_recipe_screen_offers_manual_entry(): void
    {
        $recipe = $this->recipe();

        $this->actingAs($this->user)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Paste a whole list instead')
            ->assertSee('Add a photo')
            ->assertSee('Recipe serves');
    }
}
