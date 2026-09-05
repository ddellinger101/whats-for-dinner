<?php

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\IngredientsStatus;
use App\Enums\MealSlot;
use App\Enums\ProteinType;
use App\Enums\Rating;
use App\Models\HouseholdSetting;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Services\MealPlanner;
use App\Services\RecipeSuggestionRanker;
use App\Services\UseByWindowTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spec 4.2 — the ranked suggestion list for a primary dinner slot.
 */
class SuggestionRankerTest extends TestCase
{
    use RefreshDatabase;

    private RecipeSuggestionRanker $ranker;

    private UseByWindowTracker $windows;

    /** Thursday, inside the plan week beginning Sun 2026-09-06. */
    private const THURSDAY = '2026-09-10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\HouseholdSeeder::class);
        $this->ranker = new RecipeSuggestionRanker;
        $this->windows = new UseByWindowTracker;
    }

    /**
     * @param  list<string>  $ingredientNames
     */
    private function makeRecipe(
        string $name,
        ProteinType $protein = ProteinType::Chicken,
        Rating $rating = Rating::Unrated,
        array $ingredientNames = [],
        bool $isKeto = false,
        ?string $lastCookedOn = null,
    ): Recipe {
        $recipe = Recipe::create([
            'name' => $name,
            'protein_type' => $protein,
            'rating' => $rating,
            'is_keto' => $isKeto,
            'last_cooked_on' => $lastCookedOn,
            'ingredients_status' => $ingredientNames === []
                ? IngredientsStatus::NotYetAdded
                : IngredientsStatus::ManuallyEntered,
        ]);

        foreach ($ingredientNames as $ingredientName) {
            $ingredient = Ingredient::firstOrCreate(
                ['name' => $ingredientName],
                ['category' => IngredientCategory::Dairy, 'shelf_life_days' => 12],
            );

            $recipe->ingredients()->attach($ingredient->id, [
                'id' => (string) Str::uuid(),
                'quantity_per_serving' => 1,
                'unit' => 'cup',
            ]);
        }

        return $recipe->fresh();
    }

    private function openWindowFor(string $ingredientName): void
    {
        $ingredient = Ingredient::where('name', $ingredientName)->firstOrFail();

        $this->windows->openWindow(
            $ingredient,
            Carbon::parse(self::THURSDAY),
            Carbon::parse('2026-09-06'),
        );
    }

    /** @return list<string> */
    private function rankedNames(): array
    {
        return $this->ranker->for(Carbon::parse(self::THURSDAY))
            ->map(fn ($s) => $s->recipe->name)
            ->all();
    }

    /** Spec 4.2.1: thumbs-down never appears in suggestions. */
    public function test_thumbs_down_recipes_are_excluded(): void
    {
        $this->makeRecipe('Good Dish', rating: Rating::ThumbsUp);
        $this->makeRecipe('Hated Dish', rating: Rating::ThumbsDown);

        $this->assertSame(['Good Dish'], $this->rankedNames());
        // Still in the browser, just not suggested.
        $this->assertSame(2, Recipe::count());
    }

    /** Spec 4.2.2: keto mode hides off-diet recipes from suggestions. */
    public function test_diet_mode_filters_suggestions(): void
    {
        $this->makeRecipe('Keto Bowl', isKeto: true);
        $this->makeRecipe('Pasta Bake', isKeto: false);

        $this->assertCount(2, $this->ranker->for(Carbon::parse(self::THURSDAY)));

        HouseholdSetting::current()->update(['diet_mode' => 'keto']);
        $this->assertSame(['Keto Bowl'], $this->rankedNames());
    }

    /** Spec 4.2.3: a recipe clearing an at-risk ingredient is boosted. */
    public function test_recipes_using_up_at_risk_ingredients_rank_first(): void
    {
        $this->makeRecipe('Plain Roast', rating: Rating::ThumbsUp);
        $this->makeRecipe('Creamy Bake', ingredientNames: ['Sour Cream']);
        $this->openWindowFor('Sour Cream');

        $ranked = $this->ranker->for(Carbon::parse(self::THURSDAY));

        // Beats a thumbs-up recipe despite being unrated.
        $this->assertSame('Creamy Bake', $ranked->first()->recipe->name);
        $this->assertSame(['Sour Cream'], $ranked->first()->usesUp);
        $this->assertTrue($ranked->first()->isBoosted());
        $this->assertSame('uses up: Sour Cream', $ranked->first()->reason());
    }

    /** Spec 4.2.3: more at-risk ingredients cleared means a bigger boost. */
    public function test_clearing_more_ingredients_ranks_higher(): void
    {
        $this->makeRecipe('Clears One', ingredientNames: ['Sour Cream']);
        $this->makeRecipe('Clears Two', ingredientNames: ['Sour Cream', 'Cilantro']);
        $this->openWindowFor('Sour Cream');
        $this->openWindowFor('Cilantro');

        $this->assertSame(['Clears Two', 'Clears One'], $this->rankedNames());
    }

    /** Spec 4.2.4: yesterday's protein is deprioritised, never blocked. */
    public function test_repeating_yesterdays_protein_is_deprioritised_not_removed(): void
    {
        $beef = $this->makeRecipe('Beef Chili', protein: ProteinType::Beef);
        $this->makeRecipe('Chicken Bake', protein: ProteinType::Chicken);

        (new MealPlanner)->setPrimaryRecipe(
            Carbon::parse('2026-09-09'),   // the day before
            MealSlot::Dinner,
            $beef,
        );

        $ranked = $this->ranker->for(Carbon::parse(self::THURSDAY));
        $names = $ranked->map(fn ($s) => $s->recipe->name)->all();

        $this->assertSame(['Chicken Bake', 'Beef Chili'], $names);
        // Still offered, just lower — that is the difference from an exclusion.
        $this->assertTrue($ranked->last()->repeatsYesterdaysProtein);
    }

    /** A meatless main should not suppress anything the next day. */
    public function test_a_protein_free_previous_day_suppresses_nothing(): void
    {
        $soup = $this->makeRecipe('Veg Soup', protein: ProteinType::None);
        $this->makeRecipe('Another Veg Dish', protein: ProteinType::None);

        (new MealPlanner)->setPrimaryRecipe(Carbon::parse('2026-09-09'), MealSlot::Dinner, $soup);

        foreach ($this->ranker->for(Carbon::parse(self::THURSDAY)) as $suggestion) {
            $this->assertFalse($suggestion->repeatsYesterdaysProtein);
        }
    }

    /** Spec 4.2.5: thumbs_up outranks just_ok, all else equal. */
    public function test_rating_breaks_ties(): void
    {
        $this->makeRecipe('Just OK Dish', rating: Rating::JustOk);
        $this->makeRecipe('Great Dish', rating: Rating::ThumbsUp);

        $this->assertSame(['Great Dish', 'Just OK Dish'], $this->rankedNames());
    }

    /** Spec 4.2.5: something cooked last week is nudged down. */
    public function test_recently_cooked_recipes_are_deprioritised(): void
    {
        $this->makeRecipe('Made Last Week', lastCookedOn: '2026-09-05');
        $this->makeRecipe('Not Made In Ages', lastCookedOn: '2025-01-01');

        $ranked = $this->ranker->for(Carbon::parse(self::THURSDAY));

        $this->assertSame(['Not Made In Ages', 'Made Last Week'], $ranked->map(fn ($s) => $s->recipe->name)->all());
        $this->assertTrue($ranked->last()->cookedRecently);
    }

    /** Spec 4.2.5: waste reduction wins — recency is set aside when clearing. */
    public function test_use_up_boost_overrides_the_recency_penalty(): void
    {
        $this->makeRecipe('Made Last Week', ingredientNames: ['Sour Cream'], lastCookedOn: '2026-09-05');
        $this->makeRecipe('Fresh Idea', rating: Rating::ThumbsUp);
        $this->openWindowFor('Sour Cream');

        $ranked = $this->ranker->for(Carbon::parse(self::THURSDAY));

        $this->assertSame('Made Last Week', $ranked->first()->recipe->name);
        $this->assertTrue($ranked->first()->cookedRecently);
        $this->assertTrue($ranked->first()->isBoosted());
    }

    /** An expired window must stop boosting. */
    public function test_an_expired_window_no_longer_boosts(): void
    {
        $this->makeRecipe('Creamy Bake', ingredientNames: ['Sour Cream']);
        $this->makeRecipe('Plain Roast', rating: Rating::ThumbsUp);

        $ingredient = Ingredient::where('name', 'Sour Cream')->firstOrFail();
        $ingredient->update(['shelf_life_days' => 1]);
        // Bought Sunday, good for one day: long expired by Thursday.
        $this->windows->openWindow($ingredient, Carbon::parse(self::THURSDAY), Carbon::parse('2026-09-06'));

        $ranked = $this->ranker->for(Carbon::parse(self::THURSDAY));

        $this->assertSame('Plain Roast', $ranked->first()->recipe->name);
        $this->assertFalse($ranked->firstWhere('recipe.name', 'Creamy Bake')->isBoosted());
    }

    /** Never-cooked recipes come first among equals, for variety. */
    public function test_never_cooked_recipes_win_equal_scores(): void
    {
        $this->makeRecipe('Never Made');
        $this->makeRecipe('Made Long Ago', lastCookedOn: '2024-06-01');

        $this->assertSame(['Never Made', 'Made Long Ago'], $this->rankedNames());
    }

    public function test_the_limit_is_respected(): void
    {
        foreach (range(1, 8) as $i) {
            $this->makeRecipe("Dish {$i}");
        }

        $this->assertCount(3, $this->ranker->for(Carbon::parse(self::THURSDAY), limit: 3));
    }
}
