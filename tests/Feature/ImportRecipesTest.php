<?php

namespace Tests\Feature;

use App\Enums\CategoryTag;
use App\Enums\IngredientsStatus;
use App\Enums\ProteinType;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportRecipesTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'storage/app/import/whats_for_dinner.xlsx';

    protected function setUp(): void
    {
        parent::setUp();

        // The workbook is personal data and is not committed, so these tests
        // only run where it is present.
        if (! is_file(base_path(self::SOURCE))) {
            $this->markTestSkipped('Source spreadsheet not present.');
        }
    }

    public function test_it_imports_every_dish_from_all_five_sheets(): void
    {
        $this->artisan('recipes:import')->assertSuccessful();

        $this->assertSame(143, Recipe::count());

        $byProtein = Recipe::query()
            ->selectRaw('protein_type, count(*) as total')
            ->groupBy('protein_type')
            ->pluck('total', 'protein_type')
            ->all();

        $this->assertSame(63, $byProtein[ProteinType::Chicken->value]);
        $this->assertSame(33, $byProtein[ProteinType::Beef->value]);
        $this->assertSame(22, $byProtein[ProteinType::Pork->value]);
        $this->assertSame(15, $byProtein[ProteinType::Seafood->value]);
        // The workbook's catch-all "Other" sheet now imports as Vegetarian.
        $this->assertSame(10, $byProtein[ProteinType::Vegetarian->value]);
    }

    /** Spec 4.7: nothing is blocked while ingredients are missing. */
    public function test_imported_recipes_are_flagged_as_awaiting_ingredients(): void
    {
        $this->artisan('recipes:import')->assertSuccessful();

        $this->assertSame(143, Recipe::where('created_from_import', true)->count());
        $this->assertSame(
            143,
            Recipe::where('ingredients_status', IngredientsStatus::NotYetAdded->value)->count(),
        );
    }

    /** Spec 4.7: semicolon-separated links become a list, tried in order. */
    public function test_it_splits_multiple_links(): void
    {
        $this->artisan('recipes:import')->assertSuccessful();

        $porkChops = Recipe::where('name', 'Pork Chops')->firstOrFail();

        $this->assertCount(2, $porkChops->recipe_links);
        foreach ($porkChops->recipe_links as $link) {
            $this->assertStringStartsWith('http', $link);
        }

        // 55 dishes carry no link at all and go straight to manual entry.
        $this->assertSame(55, Recipe::whereJsonLength('recipe_links', 0)->count());
    }

    public function test_times_made_carries_over(): void
    {
        $this->artisan('recipes:import')->assertSuccessful();

        $this->assertSame(15, Recipe::where('name', 'Chicken Caesar Salad')->firstOrFail()->times_made);
        $this->assertSame(11, Recipe::where('name', 'Burgers')->firstOrFail()->times_made);
    }

    /** The keto flag is inferred, since the workbook has no keto column. */
    public function test_inferred_keto_recipes_get_the_flag_and_the_tag(): void
    {
        $this->artisan('recipes:import')->assertSuccessful();

        $keto = Recipe::where('name', 'Keto Chicken Parm')->firstOrFail();

        $this->assertTrue($keto->is_keto);
        $this->assertTrue($keto->category_tags->contains(CategoryTag::Keto));

        $notKeto = Recipe::where('name', 'Burgers')->firstOrFail();
        $this->assertFalse($notKeto->is_keto);
        $this->assertCount(0, $notKeto->category_tags);
    }

    /** Re-running must not duplicate: the workbook is the source of truth. */
    public function test_import_is_idempotent(): void
    {
        $this->artisan('recipes:import')->assertSuccessful();
        $this->artisan('recipes:import')->assertSuccessful();

        $this->assertSame(143, Recipe::count());
    }

    /** A photo taken in the kitchen is never clobbered by a later scrape. */
    public function test_user_photo_survives_reimport(): void
    {
        $this->artisan('recipes:import')->assertSuccessful();

        $recipe = Recipe::where('name', 'Tacos')->firstOrFail();
        $recipe->update([
            'image_path' => 'recipes/tacos.jpg',
            'image_status' => \App\Enums\ImageStatus::Uploaded,
        ]);

        $this->assertFalse($recipe->fresh()->canAcceptScrapedImage());

        $this->artisan('recipes:import')->assertSuccessful();
        $this->assertSame('recipes/tacos.jpg', $recipe->fresh()->image_path);
    }
}
