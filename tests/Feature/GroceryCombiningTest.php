<?php

namespace Tests\Feature;

use App\Enums\GroceryAisle;
use App\Enums\GroceryItemStatus;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\MealComponent;
use App\Models\Recipe;
use App\Models\SimpleItem;
use App\Models\User;
use App\Services\GroceryListBuilder;
use App\Services\MealPlanner;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One line per thing, however many meals want it.
 *
 * A week with olive oil in three recipes put olive oil on the list three
 * times and left the adding up to whoever was holding the phone in the shop.
 */
class GroceryCombiningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const WEDNESDAY = '2026-09-09';

    private const THURSDAY = '2026-09-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
        Carbon::setTestNow(Carbon::parse(self::WEDNESDAY));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, array{float|null, string|null}>  $ingredients */
    private function recipe(string $name, array $ingredients): Recipe
    {
        $recipe = Recipe::create([
            'name' => $name,
            'base_servings' => 4,
            'ingredients_status' => IngredientsStatus::ManuallyEntered,
        ]);

        foreach ($ingredients as $ingredientName => [$perServing, $unit]) {
            $ingredient = Ingredient::firstOrCreate(
                ['name' => $ingredientName],
                ['category' => IngredientCategory::Condiment, 'shelf_life_days' => 90],
            );
            $recipe->ingredients()->attach($ingredient->id, [
                'id' => (string) Str::uuid(),
                'quantity_per_serving' => $perServing,
                'unit' => $unit,
            ]);
        }

        return $recipe->fresh();
    }

    private function plan(Recipe $recipe, string $date, MealSlot $slot = MealSlot::Dinner): MealComponent
    {
        return (new MealPlanner)->setPrimaryRecipe(Carbon::parse($date), $slot, $recipe, 4);
    }

    // --------------------------------------------------------- combining

    public function test_two_meals_wanting_the_same_thing_make_one_line(): void
    {
        $this->plan($this->recipe('Roast', ['Extra virgin olive oil' => [0.5, 'tbsp']]), self::WEDNESDAY);
        $this->plan($this->recipe('Pasta', ['Extra virgin olive oil' => [0.25, 'tbsp']]), self::THURSDAY);

        $lines = GroceryListItem::where('item_name', 'Extra virgin olive oil')->get();

        $this->assertCount(1, $lines, 'one line, not one per meal');
        // Half a tbsp per serving over four, plus a quarter over four: 2 + 1.
        $this->assertEqualsWithDelta(3.0, (float) $lines->first()->quantity, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $lines->first()->planned_quantity, 0.001);
    }

    /** The line says what it is buying for, without naming only the first. */
    public function test_the_line_names_the_meals_it_is_buying_for(): void
    {
        $oil = ['Extra virgin olive oil' => [0.5, 'tbsp']];
        $this->plan($this->recipe('Roast', $oil), self::WEDNESDAY);

        $line = GroceryListItem::where('item_name', 'Extra virgin olive oil')->firstOrFail();
        $this->assertSame('for Roast', $line->fresh()->load('sources.mealComponent')->reasonLabel());

        $this->plan($this->recipe('Pasta', $oil), self::THURSDAY);
        $this->assertSame(
            'for Roast and Pasta',
            $line->fresh()->load('sources.mealComponent')->reasonLabel(),
        );

        $this->plan($this->recipe('Stew', $oil), self::WEDNESDAY, MealSlot::Lunch);
        $this->assertSame('for 3 meals', $line->fresh()->load('sources.mealComponent')->reasonLabel());
    }

    /**
     * The case that prompted this: one recipe in tablespoons and another in
     * cups made two lines of the same oil. The conversion is exact, so the
     * shopper should not be doing it in an aisle.
     */
    public function test_amounts_in_different_volumes_are_converted_and_added(): void
    {
        // 0.5 tbsp per serving over four servings: 2 tbsp.
        $this->plan($this->recipe('Roast', ['Extra virgin olive oil' => [0.5, 'tbsp']]), self::WEDNESDAY);
        // 0.25 cup per serving over four: 1 cup, which is 16 tbsp.
        $this->plan($this->recipe('Pasta', ['Extra virgin olive oil' => [0.25, 'cup']]), self::THURSDAY);

        $lines = GroceryListItem::where('item_name', 'Extra virgin olive oil')->get();

        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(18.0, (float) $lines->first()->quantity, 0.001);
        $this->assertSame('tbsp', $lines->first()->unit);
    }

    /**
     * A clove and a can are units of different things, and no arithmetic turns
     * one into the other. Two lines beats an invented total.
     */
    public function test_units_that_do_not_convert_stay_apart(): void
    {
        $this->plan($this->recipe('Roast', ['Garlic' => [1.0, 'clove']]), self::WEDNESDAY);
        $this->plan($this->recipe('Pasta', ['Garlic' => [1.0, 'head']]), self::THURSDAY);

        $this->assertCount(2, GroceryListItem::where('item_name', 'Garlic')->get());
    }

    /** Weight converts too, and says the total in a sensible unit. */
    public function test_weights_convert_and_step_up_to_the_larger_unit(): void
    {
        // 4 oz per serving over four servings is 16 oz; plus 1 lb is 2 lb.
        $this->plan($this->recipe('Roast', ['Beef chuck' => [4.0, 'oz']]), self::WEDNESDAY);
        $this->plan($this->recipe('Stew', ['Beef chuck' => [0.25, 'lb']]), self::THURSDAY);

        $line = GroceryListItem::where('item_name', 'Beef chuck')->firstOrFail();

        $this->assertSame('lb', $line->unit, 'two pounds, not thirty-two ounces');
        $this->assertEqualsWithDelta(2.0, (float) $line->quantity, 0.01);
    }

    /** An unknown amount does not add up to a number. */
    public function test_an_unquantified_contribution_leaves_the_total_unknown(): void
    {
        $this->plan($this->recipe('Roast', ['Olive oil' => [2.0, null]]), self::WEDNESDAY);
        $this->plan($this->recipe('Pasta', ['Olive oil' => [null, null]]), self::THURSDAY);

        $line = GroceryListItem::where('item_name', 'Olive oil')->firstOrFail();

        $this->assertCount(1, GroceryListItem::where('item_name', 'Olive oil')->get());
        $this->assertNull($line->planned_quantity);
    }

    public function test_simple_items_combine_too(): void
    {
        $item = SimpleItem::create(['name' => 'Sandwiches', 'grocery_breakdown' => ['Bread', 'Ham']]);

        (new MealPlanner)->addSide(Carbon::parse(self::WEDNESDAY), MealSlot::Lunch, $item);
        (new MealPlanner)->addSide(Carbon::parse(self::THURSDAY), MealSlot::Lunch, $item);

        $this->assertCount(1, GroceryListItem::where('item_name', 'Bread')->get());
        $this->assertSame(2, GroceryListItem::needed()->count());
    }

    // ---------------------------------------------------------- removing

    /** Dropping one meal takes back its share and leaves the rest. */
    public function test_removing_one_meal_subtracts_only_its_share(): void
    {
        $oil = ['Extra virgin olive oil' => [0.5, 'tbsp']];
        $this->plan($this->recipe('Roast', $oil), self::WEDNESDAY);
        $pasta = $this->plan($this->recipe('Pasta', $oil), self::THURSDAY);

        $line = GroceryListItem::where('item_name', 'Extra virgin olive oil')->firstOrFail();
        $this->assertEqualsWithDelta(4.0, (float) $line->quantity, 0.001);

        (new GroceryListBuilder)->removeForComponent($pasta);

        $line->refresh();
        $this->assertSame(GroceryItemStatus::Needed, $line->status);
        $this->assertEqualsWithDelta(2.0, (float) $line->quantity, 0.001);
        $this->assertCount(1, $line->fresh()->sources);
    }

    /** The last meal leaving takes the line with it. */
    public function test_removing_the_only_meal_removes_the_line(): void
    {
        $roast = $this->plan($this->recipe('Roast', ['Extra virgin olive oil' => [0.5, 'tbsp']]), self::WEDNESDAY);

        (new GroceryListBuilder)->removeForComponent($roast);

        $this->assertSame(0, GroceryListItem::where('item_name', 'Extra virgin olive oil')->count());
    }

    /**
     * Once it is ticked off the line records a shopping decision rather than a
     * plan, so a meal leaving must not quietly reduce what was actually bought.
     */
    public function test_a_bought_line_is_left_alone(): void
    {
        $oil = ['Extra virgin olive oil' => [0.5, 'tbsp']];
        $this->plan($this->recipe('Roast', $oil), self::WEDNESDAY);
        $pasta = $this->plan($this->recipe('Pasta', $oil), self::THURSDAY);

        $line = GroceryListItem::where('item_name', 'Extra virgin olive oil')->firstOrFail();
        $this->actingAs($this->user)->post(route('grocery.toggle', $line));

        (new GroceryListBuilder)->removeForComponent($pasta);

        $line->refresh();
        $this->assertSame(GroceryItemStatus::Purchased, $line->status);
        $this->assertEqualsWithDelta(4.0, (float) $line->quantity, 0.001);
    }

    /**
     * The whole point of editing the amount is that the shop sells a pack of
     * eight when the week needs two, so a later change to the plan must not
     * overwrite that.
     */
    public function test_an_edited_amount_survives_another_meal_being_added(): void
    {
        $oil = ['Extra virgin olive oil' => [0.5, 'tbsp']];
        $this->plan($this->recipe('Roast', $oil), self::WEDNESDAY);

        $line = GroceryListItem::where('item_name', 'Extra virgin olive oil')->firstOrFail();
        $this->actingAs($this->user)
            ->post(route('grocery.quantity', $line), ['quantity' => 16, 'unit' => 'tbsp']);

        $this->plan($this->recipe('Pasta', $oil), self::THURSDAY);

        $line->refresh();
        $this->assertEqualsWithDelta(16.0, (float) $line->quantity, 0.001, 'the bottle you are buying');
        $this->assertEqualsWithDelta(4.0, (float) $line->planned_quantity, 0.001, 'what the week needs');
    }

    // ------------------------------------------------ the existing list

    /**
     * New lines combine as they are added, but a list built before that still
     * holds a row per meal, which is what the household was looking at.
     */
    public function test_the_command_combines_lines_already_on_the_list(): void
    {
        $builder = new GroceryListBuilder;
        $builder->addManual('Extra virgin olive oil', 1.0, 'tbsp');
        $builder->addManual('extra virgin olive oil', 2.0, 'tbsp');
        $builder->addManual('Bananas');

        $this->artisan('grocery:combine')->assertSuccessful();
        $this->assertSame(3, GroceryListItem::needed()->count(), 'the dry run writes nothing');

        $this->artisan('grocery:combine --apply')->assertSuccessful();

        $this->assertSame(2, GroceryListItem::needed()->count());
        $oil = GroceryListItem::whereRaw('LOWER(item_name) = ?', ['extra virgin olive oil'])->firstOrFail();
        $this->assertEqualsWithDelta(3.0, (float) $oil->quantity, 0.001);
    }

    /** A ticked-off line records what was bought; merging it rewrites history. */
    public function test_bought_lines_are_left_out_of_it(): void
    {
        $builder = new GroceryListBuilder;
        $bought = $builder->addManual('Extra virgin olive oil', 1.0, 'tbsp');
        $builder->addManual('Extra virgin olive oil', 2.0, 'tbsp');
        $bought->markPurchased();

        $this->artisan('grocery:combine --apply')->assertSuccessful();

        $this->assertSame(2, GroceryListItem::count());
    }

    // ------------------------------------------------------------- aisles

    /**
     * "Extra virgin olive oil" was filed under Drinks: the aisle guesser
     * matched on plain substrings, and "gin" is inside "virgin".
     */
    public function test_olive_oil_is_pantry_not_drinks(): void
    {
        $this->plan($this->recipe('Roast', ['Extra virgin olive oil' => [0.5, 'tbsp']]), self::WEDNESDAY);

        $line = GroceryListItem::where('item_name', 'Extra virgin olive oil')->firstOrFail();

        $this->assertSame(GroceryAisle::Pantry, $line->aisle);
    }
}
