<?php

namespace Tests\Feature;

use App\Enums\GroceryAisle;
use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use App\Services\IngredientResolver;
use App\Support\AisleGuesser;
use App\Support\IngredientCategoryGuesser;
use App\Support\SpiritName;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cocktail recipes name the bottle the author happened to own. Left alone,
 * every brand becomes its own ingredient, and a bar holding one bottle of
 * bourbon matches none of the three recipes calling for bourbon under three
 * different names.
 */
class SpiritNamesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        User::factory()->create();
    }

    /** The case from the household's own Old Fashioned. */
    public function test_a_branded_bottle_reduces_to_the_spirit(): void
    {
        $this->assertSame('Bourbon', SpiritName::generic('Colonel e.h. taylor small batch bourbon'));
        $this->assertSame('Vodka', SpiritName::generic('Tito’s Handmade Vodka'));
        $this->assertSame('Triple sec', SpiritName::generic('De Kuyper Triple Sec'));
        $this->assertSame('Amaretto', SpiritName::generic('Disaronno Amaretto'));
    }

    /**
     * A style that sends you to a different bottle is kept; a brand is not.
     * Dark rum and coconut rum are different things to buy, Bulleit and
     * Maker's Mark are not.
     */
    public function test_a_style_survives_but_a_brand_does_not(): void
    {
        $this->assertSame('Coconut rum', SpiritName::generic('Malibu Coconut Rum'));
        $this->assertSame('Silver tequila', SpiritName::generic('El Jimador Silver Tequila'));
        $this->assertSame('Rye whiskey', SpiritName::generic('Bulleit Rye Whiskey'));
    }

    /**
     * The spirit has to be the head noun. This is what separates a bottle of
     * rum from a rum cake, and it is the only guard against the reduction
     * eating half the archive.
     */
    public function test_only_the_head_noun_counts(): void
    {
        foreach (['Rum cake', 'Rum extract', 'Vanilla extract', 'Gin gin bread', 'Bourbon glazed ham'] as $name) {
            $this->assertNull(SpiritName::generic($name), $name);
        }
    }

    /** Nothing to say about a name that is already generic. */
    public function test_an_already_generic_name_is_left_alone(): void
    {
        foreach (['Bourbon', 'Vodka', 'Dark rum', 'Sweet vermouth', 'Angostura bitters'] as $name) {
            $this->assertNull(SpiritName::generic($name), $name);
        }
    }

    // ------------------------------------------------------- the resolver

    /** Three brands, one bottle of bourbon. */
    public function test_every_brand_resolves_to_the_one_ingredient(): void
    {
        $resolver = new IngredientResolver;

        $ids = collect([
            'Colonel E.H. Taylor Small Batch Bourbon',
            'Maker’s Mark Bourbon',
            'Bulleit Bourbon',
            'bourbon',
        ])->map(fn (string $name) => $resolver->resolve($name)->id)->unique();

        $this->assertCount(1, $ids);
        $this->assertSame('Bourbon', Ingredient::find($ids->first())->name);
        $this->assertSame(IngredientCategory::Bar, Ingredient::find($ids->first())->category);
    }

    /** And the dry run agrees with what resolving would do. */
    public function test_find_reduces_the_same_way_resolve_does(): void
    {
        $resolver = new IngredientResolver;
        $bourbon = $resolver->resolve('Bourbon');

        $this->assertTrue($bourbon->is($resolver->find('Woodford Reserve Bourbon')));
    }

    /**
     * A syrup is a syrup wherever it is destined. Demerara and simple syrup
     * live in the fridge with the maple and the table syrup, so they are
     * filed with them — a section that does not say where a thing actually is
     * is worth nothing.
     */
    public function test_syrups_are_condiments_whatever_they_are_for(): void
    {
        $categories = new IngredientCategoryGuesser;
        $aisles = new AisleGuesser;

        foreach (['Rich demerara syrup', 'Simple syrup', 'Orgeat', 'Grenadine',
            'Agave nectar', 'Table syrup', 'Maple syrup'] as $item) {
            $this->assertSame(IngredientCategory::Condiment, $categories->guess($item), $item);
            $this->assertSame(GroceryAisle::Pantry, $aisles->guess($item, null, false), $item);
        }

        // The bar keeps the bottles, the bitters and the garnishes.
        foreach (['Bourbon', 'Angostura bitters', 'Cocktail cherries', 'Tonic water'] as $item) {
            $this->assertSame(IngredientCategory::Bar, $categories->guess($item), $item);
        }
    }
}
