<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Services\IngredientResolver;
use App\Support\IngredientDescriptors;
use App\Support\IngredientLine;
use App\Support\PantryStaples;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Names that describe the same thing should file as the same thing.
 *
 * Left alone, "large eggs" and "eggs" are two rows with two lots of pantry
 * stock and two grocery lines, and the app ends up believing the house keeps
 * four kinds of butter.
 */
class IngredientSynonymTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
    }

    private function resolve(string $name): Ingredient
    {
        return (new IngredientResolver)->resolve($name);
    }

    /** Every pair the household named, from the line as a recipe writes it. */
    public function test_described_ingredients_file_as_the_plain_thing(): void
    {
        foreach ([
            '1/2 cup unsalted butter' => 'Butter',
            '2 tbsp salted butter' => 'Butter',
            '2 large eggs' => 'Eggs',
            '1 lb lean ground beef' => 'Ground beef',
            '1/4 cup extra virgin olive oil' => 'Olive oil',
            '2 tbsp low-sodium soy sauce' => 'Soy sauce',
            '3 boneless skinless chicken breasts' => 'Chicken breasts',
        ] as $line => $expected) {
            $this->assertSame(
                $expected,
                IngredientDescriptors::strip(IngredientLine::parse($line)->name),
                $line,
            );
        }
    }

    /**
     * "Boneless" arrived as an ingredient in its own right: the parser took
     * everything before the first comma, and in "boneless, skinless chicken
     * breasts" that is a description with no thing attached.
     */
    public function test_a_comma_between_descriptors_does_not_end_the_name(): void
    {
        $this->assertSame(
            'Chicken breasts',
            IngredientDescriptors::strip(IngredientLine::parse('1 lb boneless, skinless chicken breasts')->name),
        );

        // A comma after a real name still ends it, which is what it is for.
        $this->assertSame('Onion', IngredientLine::parse('1 onion, finely chopped')->name);
        $this->assertSame('Garlic', IngredientLine::parse('2 cloves garlic, crushed')->name);
    }

    /** One row, however the recipe described it. */
    public function test_every_description_resolves_to_one_row(): void
    {
        $ids = collect(['Butter', 'Unsalted butter', 'Salted butter', 'Large unsalted butter'])
            ->map(fn (string $name) => $this->resolve($name)->id)
            ->unique();

        $this->assertCount(1, $ids);
        $this->assertSame('Butter', Ingredient::find($ids->first())->name);
    }

    /**
     * What is missing from the list matters more than what is in it. Each of
     * these names a different thing from the word it contains.
     */
    public function test_words_that_change_what_something_is_are_kept(): void
    {
        foreach ([
            'Ground beef' => 'Ground beef',
            'Whole milk' => 'Whole milk',
            'Whole chicken' => 'Whole chicken',
            'Fresh basil' => 'Fresh basil',
            'Dried thyme' => 'Dried thyme',
            'Unsweetened almond milk' => 'Unsweetened almond milk',
            'Smoked paprika' => 'Smoked paprika',
        ] as $name => $expected) {
            $this->assertSame($expected, IngredientDescriptors::strip($name), $name);
        }
    }

    /**
     * A leading "ground" is a grind, not a different spice — but it is very
     * much a different beef.
     */
    public function test_a_ground_spice_is_the_spice(): void
    {
        $this->assertSame('Cayenne pepper', PantryStaples::match('Ground cayenne pepper')?->name);
        $this->assertSame('Black pepper', PantryStaples::match('Ground black pepper')?->name);

        $this->assertNull(PantryStaples::match('Ground beef'));
        $this->assertNull(PantryStaples::match('Ground turkey'));
    }

    /** Stripping everything away leaves the original, not a blank row. */
    public function test_a_name_that_is_only_description_survives(): void
    {
        $this->assertSame('Boneless', IngredientDescriptors::strip('Boneless'));
        $this->assertSame('Large', IngredientDescriptors::strip('Large'));
    }

    /** Taking a word out of "medium or large shrimp" must not strand the "or". */
    public function test_a_stranded_connective_is_cleaned_up(): void
    {
        $this->assertSame('Shrimp', IngredientDescriptors::strip('Medium or large shrimp'));
        $this->assertSame('Shrimp', IngredientDescriptors::strip('Large shrimp'));
    }
}
