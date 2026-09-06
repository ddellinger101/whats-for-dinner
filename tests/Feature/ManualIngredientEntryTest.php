<?php

namespace Tests\Feature;

use App\Enums\ImageStatus;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
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
            tap(\Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class), function ($disk) {
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
