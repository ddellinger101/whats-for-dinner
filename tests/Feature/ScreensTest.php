<?php

namespace Tests\Feature;

use App\Enums\GroceryItemStatus;
use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Enums\Rating;
use App\Models\GroceryListItem;
use App\Models\Ingredient;
use App\Models\InventoryFlag;
use App\Models\MealComponent;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\SimpleItem;
use App\Models\User;
use App\Services\MealPlanner;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private const WEDNESDAY = '2026-09-09';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(HouseholdSeeder::class);
        $this->user = User::factory()->create();
    }

    private function makeRecipe(string $name, array $ingredients = [], ProteinType $protein = ProteinType::Chicken): Recipe
    {
        $recipe = Recipe::create([
            'name' => $name,
            'protein_type' => $protein,
            'base_servings' => 4,
            'ingredients_status' => $ingredients === []
                ? IngredientsStatus::NotYetAdded
                : IngredientsStatus::ManuallyEntered,
        ]);

        foreach ($ingredients as $ingredientName) {
            $ingredient = Ingredient::firstOrCreate(
                ['name' => $ingredientName],
                ['category' => IngredientCategory::Produce, 'shelf_life_days' => 6],
            );
            $recipe->ingredients()->attach($ingredient->id, [
                'id' => (string) Str::uuid(),
                'quantity_per_serving' => 0.5,
                'unit' => 'cup',
            ]);
        }

        return $recipe->fresh();
    }

    // ---------------------------------------------------------------- auth

    /** Household data must never be reachable without signing in. */
    public function test_app_screens_require_authentication(): void
    {
        foreach (['/', '/tonight', '/plan', '/grocery', '/recipes'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    /** The legal pages stay public, or Google cannot fetch them. */
    public function test_legal_pages_stay_public(): void
    {
        $this->get('/privacy-policy')->assertOk();
        $this->get('/terms')->assertOk();
    }

    public function test_a_household_member_can_sign_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'cook@example.com', 'password' => bcrypt('dinner-time')]);

        $this->post('/login', ['email' => 'cook@example.com', 'password' => 'dinner-time'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_bad_credentials_are_rejected(): void
    {
        User::factory()->create(['email' => 'cook@example.com', 'password' => bcrypt('dinner-time')]);

        $this->post('/login', ['email' => 'cook@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** There is deliberately no public registration route. */
    public function test_there_is_no_registration_route(): void
    {
        $this->post('/register', [])->assertNotFound();
    }

    /** Accounts come from the console command instead. */
    public function test_the_console_command_creates_an_account(): void
    {
        $this->artisan('household:user', [
            'email' => 'partner@example.com',
            '--password' => 'shared-kitchen',
            '--name' => 'Partner',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'partner@example.com', 'name' => 'Partner']);

        $this->post('/login', ['email' => 'partner@example.com', 'password' => 'shared-kitchen'])
            ->assertRedirect(route('home'));
    }

    // --------------------------------------------------------------- screens

    public function test_home_renders_with_counts(): void
    {
        $this->makeRecipe('Tacos');

        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertSee("What's For Dinner", false)
            ->assertSee('Meal Plan');
    }

    public function test_the_week_grid_renders_every_day_and_slot(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('plan', ['start' => '2026-09-06']));

        $response->assertOk();

        foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day) {
            $response->assertSee($day);
        }
        foreach (MealSlot::ordered() as $slot) {
            $response->assertSee($slot->label());
        }
    }

    /**
     * A planned meal was a label with a remove button and nothing else — there
     * was no way to see what was in it without hunting the recipe down. It now
     * opens in place, with the amounts scaled to what this slot is planned for
     * rather than to the recipe's own yield.
     */
    public function test_a_planned_meal_opens_its_recipe_in_place(): void
    {
        $recipe = $this->makeRecipe('Chicken Bake', ['Double cream']);
        $recipe->update(['instructions' => ['Heat the oven', 'Bake for an hour']]);

        // Four servings at half a cup each; planned for eight, so a full litre.
        $component = (new MealPlanner)->setPrimaryRecipe(
            Carbon::parse(self::WEDNESDAY),
            MealSlot::Dinner,
            $recipe,
            8,
        );

        $response = $this->actingAs($this->user)
            ->get(route('plan', ['start' => '2026-09-06']))
            ->assertOk();

        $response->assertSee("meal-{$component->id}", false);
        $response->assertSee('Double cream');
        $response->assertSee('Bake for an hour');
        $response->assertSee('4 cup');
        $response->assertSee('Open the full recipe');
    }

    /** A bought item has no recipe, so it says what it puts on the list. */
    public function test_a_planned_simple_item_shows_what_it_buys(): void
    {
        $item = SimpleItem::create([
            'name' => 'Taco Night',
            'grocery_breakdown' => ['Tortillas', 'Ground beef', 'Cheddar'],
        ]);

        (new MealPlanner)->addSide(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $item);

        $this->actingAs($this->user)
            ->get(route('plan', ['start' => '2026-09-06']))
            ->assertOk()
            ->assertSee('Tortillas')
            ->assertSee('bought rather than cooked');
    }

    public function test_the_dinner_picker_shows_ranked_suggestions(): void
    {
        $this->makeRecipe('Chicken Bake');

        $this->actingAs($this->user)
            ->get(route('plan.picker', ['date' => self::WEDNESDAY, 'slot' => 'dinner']))
            ->assertOk()
            ->assertSee('Suggestions')
            ->assertSee('Chicken Bake');
    }

    /**
     * The point of tagging banana cookies as breakfast is that they turn up
     * when you are deciding breakfast. Before this the slot only showed a
     * recipe if you already knew its name and typed it.
     */
    public function test_the_breakfast_picker_offers_recipes_tagged_breakfast(): void
    {
        $cookies = $this->makeRecipe('Banana Cookies');
        $cookies->update(['category_tags' => ['dessert', 'breakfast']]);
        $this->makeRecipe('Beef Stroganoff');

        $response = $this->actingAs($this->user)
            ->get(route('plan.picker', ['date' => self::WEDNESDAY, 'slot' => 'breakfast']))
            ->assertOk();

        $this->assertSame(['Banana Cookies'], $response->viewData('recipes')->pluck('name')->all());
    }

    /** A dinner slot ranks the whole library, so it is not narrowed by a tag. */
    public function test_the_dinner_picker_is_not_filtered_to_a_course_tag(): void
    {
        $this->makeRecipe('Beef Stroganoff');

        $this->actingAs($this->user)
            ->get(route('plan.picker', ['date' => self::WEDNESDAY, 'slot' => 'dinner']))
            ->assertOk()
            ->assertSee('Beef Stroganoff');
    }

    /** Spec 4.5: breakfast and lunch default to the simple-item library. */
    public function test_the_lunch_picker_offers_saved_items_not_the_ranker(): void
    {
        SimpleItem::create(['name' => 'Turkey Sandwich']);
        $this->makeRecipe('Chicken Bake');

        $this->actingAs($this->user)
            ->get(route('plan.picker', ['date' => self::WEDNESDAY, 'slot' => 'lunch']))
            ->assertOk()
            ->assertSee('Saved items')
            ->assertSee('Turkey Sandwich')
            ->assertDontSee('Suggestions');
    }

    public function test_choosing_a_main_fills_the_slot_and_builds_the_list(): void
    {
        $recipe = $this->makeRecipe('Chilli', ['Onion']);

        $this->actingAs($this->user)
            ->post(route('plan.primary', ['date' => self::WEDNESDAY, 'slot' => 'dinner']), [
                'recipe_id' => $recipe->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, MealComponent::where('is_primary', true)->count());
        // Spec 4.6: groceries appear the moment the component is assigned.
        $this->assertSame(['Onion'], GroceryListItem::pluck('item_name')->all());
    }

    /** Spec 4.2.1: thumbs-down is blocked server-side, not just hidden in the UI. */
    public function test_a_thumbs_down_recipe_cannot_be_assigned(): void
    {
        $recipe = $this->makeRecipe('Hated Dish');
        $recipe->update(['rating' => Rating::ThumbsDown]);

        $this->actingAs($this->user)
            ->post(route('plan.primary', ['date' => self::WEDNESDAY, 'slot' => 'dinner']), [
                'recipe_id' => $recipe->id,
            ])
            ->assertSessionHasErrors('recipe_id');

        $this->assertSame(0, MealComponent::count());
    }

    /** Spec 4.5: a name typed on the fly joins the reusable library. */
    public function test_a_new_side_typed_on_the_fly_is_saved_for_reuse(): void
    {
        $this->actingAs($this->user)
            ->post(route('plan.side', ['date' => self::WEDNESDAY, 'slot' => 'lunch']), [
                'new_item_name' => 'Cucumber Slices',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('simple_items', ['name' => 'Cucumber Slices']);
        $this->assertSame(['Cucumber Slices'], GroceryListItem::pluck('item_name')->all());
    }

    public function test_removing_a_component_retracts_its_groceries(): void
    {
        $recipe = $this->makeRecipe('Stew', ['Carrot']);
        $component = (new MealPlanner)->setPrimaryRecipe(
            Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe,
        );

        $this->assertSame(1, GroceryListItem::count());

        $this->actingAs($this->user)
            ->delete(route('plan.component.remove', $component))
            ->assertRedirect();

        $this->assertSame(0, MealComponent::count());
        $this->assertSame(0, GroceryListItem::count());
    }

    public function test_servings_can_be_pinned_from_the_grid(): void
    {
        $this->actingAs($this->user)
            ->post(route('plan.servings', ['date' => self::WEDNESDAY, 'slot' => 'dinner']), ['servings' => 12])
            ->assertRedirect();

        $entry = MealPlanEntry::firstOrFail();
        $this->assertSame(12, $entry->household_size_used);
        $this->assertTrue($entry->servings_manually_set);
    }

    /** Spec 5: tonight's ingredients arrive already scaled. */
    public function test_tonight_shows_scaled_ingredients(): void
    {
        Carbon::setTestNow(Carbon::parse(self::WEDNESDAY));

        $recipe = $this->makeRecipe('Chilli', ['Onion']);
        (new MealPlanner)->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $this->actingAs($this->user)->get('/tonight')
            ->assertOk()
            ->assertSee('Chilli')
            ->assertSee('Onion')
            // 0.5/serving x 4 servings x multiplier 2 for a 5-person Wednesday.
            ->assertSee('4 cup');

        Carbon::setTestNow();
    }

    public function test_tonight_is_graceful_when_nothing_is_planned(): void
    {
        $this->actingAs($this->user)->get('/tonight')
            ->assertOk()
            ->assertSee('Nothing planned yet');
    }

    // --------------------------------------------------------------- grocery

    public function test_items_can_be_added_ticked_off_and_restored(): void
    {
        $this->actingAs($this->user)
            ->post(route('grocery.store'), ['item_name' => 'Dish Soap'])
            ->assertRedirect();

        $item = GroceryListItem::firstOrFail();
        $this->assertSame(GroceryItemStatus::Needed, $item->status);

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));
        $this->assertSame(GroceryItemStatus::Purchased, $item->fresh()->status);

        $this->actingAs($this->user)->post(route('grocery.toggle', $item));
        $this->assertSame(GroceryItemStatus::Needed, $item->fresh()->status);
    }

    /** Spec 5: flagging stock from the list takes the item off it. */
    public function test_marking_an_ingredient_as_stocked_flags_it_and_clears_the_line(): void
    {
        $recipe = $this->makeRecipe('Soup', ['Carrot']);
        (new MealPlanner)->setPrimaryRecipe(Carbon::parse(self::WEDNESDAY), MealSlot::Dinner, $recipe);

        $item = GroceryListItem::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('grocery.stocked', $item))
            ->assertRedirect();

        $this->assertSame(0, GroceryListItem::count());
        $this->assertTrue(
            InventoryFlag::where('ingredient_id', $item->ingredient_id)->firstOrFail()->has_stock,
        );
    }

    public function test_a_manual_line_cannot_be_marked_stocked(): void
    {
        $this->actingAs($this->user)->post(route('grocery.store'), ['item_name' => 'Batteries']);
        $item = GroceryListItem::firstOrFail();

        $this->actingAs($this->user)
            ->post(route('grocery.stocked', $item))
            ->assertSessionHasErrors('item');

        $this->assertSame(1, GroceryListItem::count());
    }

    // --------------------------------------------------------------- recipes

    public function test_the_browser_lists_and_filters_recipes(): void
    {
        $this->makeRecipe('Chicken Bake', protein: ProteinType::Chicken);
        $this->makeRecipe('Beef Chilli', protein: ProteinType::Beef);

        $this->actingAs($this->user)->get(route('recipes'))
            ->assertOk()->assertSee('Chicken Bake')->assertSee('Beef Chilli');

        $this->actingAs($this->user)->get(route('recipes', ['protein' => 'beef']))
            ->assertOk()->assertSee('Beef Chilli')->assertDontSee('Chicken Bake');

        $this->actingAs($this->user)->get(route('recipes', ['q' => 'Chicken']))
            ->assertOk()->assertSee('Chicken Bake')->assertDontSee('Beef Chilli');
    }

    /** Spec 5: thumbs-down stays browsable, with an un-grey action. */
    public function test_thumbs_down_recipes_remain_visible_with_a_way_back(): void
    {
        $recipe = $this->makeRecipe('Hated Dish');
        $recipe->update(['rating' => Rating::ThumbsDown]);

        $this->actingAs($this->user)->get(route('recipes'))->assertOk()->assertSee('Hated Dish');

        $this->actingAs($this->user)->get(route('recipes.show', $recipe))
            ->assertOk()
            ->assertSee('Hidden from suggestions')
            ->assertSee('Put it back in rotation');
    }

    /** Spec 4.4: just_ok carries a note about improving it next time. */
    public function test_rating_a_recipe_saves_the_note(): void
    {
        $recipe = $this->makeRecipe('Okay Dish');

        $this->actingAs($this->user)
            ->post(route('recipes.rate', $recipe), [
                'rating' => Rating::JustOk->value,
                'notes' => 'Needs more salt and 10 minutes less in the oven.',
            ])
            ->assertRedirect();

        $recipe->refresh();
        $this->assertSame(Rating::JustOk, $recipe->rating);
        $this->assertStringContainsString('more salt', $recipe->notes);
    }

    /**
     * A rating is never required. The radios share a form with the notes box,
     * so requiring one meant nothing could be written about a recipe until
     * somebody had cooked it — including every recipe just added.
     */
    public function test_notes_can_be_saved_without_choosing_a_rating(): void
    {
        $recipe = $this->makeRecipe('Newly Added');
        $this->assertSame(Rating::Unrated, $recipe->rating);

        $this->actingAs($this->user)
            ->post(route('recipes.rate', $recipe), ['notes' => 'Looks promising, try it Thursday.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $recipe->refresh();
        $this->assertSame('Looks promising, try it Thursday.', $recipe->notes);
        // And the rating is left exactly as it was.
        $this->assertSame(Rating::Unrated, $recipe->rating);
    }

    /** Rating something must not wipe the note already written about it. */
    public function test_rating_without_notes_keeps_the_existing_note(): void
    {
        $recipe = $this->makeRecipe('Dinner');
        $recipe->update(['notes' => 'Needs more salt.']);

        $this->actingAs($this->user)
            ->post(route('recipes.rate', $recipe), ['rating' => Rating::ThumbsUp->value]);

        $recipe->refresh();
        $this->assertSame(Rating::ThumbsUp, $recipe->rating);
        $this->assertSame('Needs more salt.', $recipe->notes);
    }

    /** An emptied box means "clear it", not "leave it alone". */
    public function test_a_note_can_be_cleared(): void
    {
        $recipe = $this->makeRecipe('Dinner');
        $recipe->update(['notes' => 'Old note.']);

        $this->actingAs($this->user)
            ->post(route('recipes.rate', $recipe), ['rating' => Rating::JustOk->value, 'notes' => '']);

        $this->assertNull($recipe->fresh()->notes);
    }

    /** Spec 4.2.5: marking as made moves both the count and the recency clock. */
    public function test_marking_a_recipe_as_made_updates_history(): void
    {
        $recipe = $this->makeRecipe('Tacos');
        $this->assertSame(0, $recipe->times_made);

        $this->actingAs($this->user)->post(route('recipes.cooked', $recipe))->assertRedirect();

        $recipe->refresh();
        $this->assertSame(1, $recipe->times_made);
        $this->assertNotNull($recipe->last_cooked_on);
    }
}
