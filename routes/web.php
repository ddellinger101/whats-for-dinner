<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\GroceryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\PantryController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeIngredientController;
use App\Http\Controllers\SimpleItemController;
use App\Http\Controllers\TonightController;
use Illuminate\Support\Facades\Route;

/*
 * Public legal pages. Google's OAuth verification requires a reachable privacy
 * policy and terms page on the same domain before it will verify the Calendar
 * scope, so these must stay outside the auth middleware. Laravel normalises the
 * trailing slash, so /terms and /terms/ both land here.
 */
Route::view('/privacy-policy', 'legal.privacy', [
    'updated' => config('app.legal_updated'),
    'contactEmail' => config('app.contact_email'),
])->name('legal.privacy');

Route::view('/terms', 'legal.terms', [
    'updated' => config('app.legal_updated'),
    'contactEmail' => config('app.contact_email'),
])->name('legal.terms');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
 * Everything else is the household's own data. There is no per-user
 * partitioning by design: both accounts see one plan and one grocery list.
 */
Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');

    Route::get('/tonight', TonightController::class)->name('tonight');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/schedule', [SettingsController::class, 'updateSchedule'])->name('settings.schedule');

    // Spec 4.8. The callback path has to match what is registered on the OAuth
    // client exactly, so it lives at the root rather than under /settings.
    Route::get('/settings/google/connect', [GoogleController::class, 'connect'])->name('google.connect');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
    Route::post('/settings/google/calendar', [GoogleController::class, 'selectCalendar'])->name('google.calendar');
    Route::post('/settings/google/disconnect', [GoogleController::class, 'disconnect'])->name('google.disconnect');
    Route::post('/settings/google/backfill', [GoogleController::class, 'backfill'])->name('google.backfill');

    Route::get('/plan', [MealPlanController::class, 'index'])->name('plan');
    Route::get('/plan/{date}/{slot}/add', [MealPlanController::class, 'picker'])->name('plan.picker');
    Route::post('/plan/{date}/{slot}/primary', [MealPlanController::class, 'setPrimary'])->name('plan.primary');
    Route::post('/plan/{date}/{slot}/side', [MealPlanController::class, 'addSide'])->name('plan.side');
    Route::post('/plan/{date}/{slot}/servings', [MealPlanController::class, 'setServings'])->name('plan.servings');
    Route::delete('/plan/component/{component}', [MealPlanController::class, 'removeComponent'])->name('plan.component.remove');

    // Spec 4.5: the simple-item library and its one-time breakdown prompt.
    Route::get('/items', [SimpleItemController::class, 'index'])->name('items');
    Route::get('/items/{simpleItem}/edit', [SimpleItemController::class, 'edit'])->name('items.edit');
    Route::post('/items/{simpleItem}', [SimpleItemController::class, 'update'])->name('items.update');
    Route::post('/items/{simpleItem}/skip', [SimpleItemController::class, 'skip'])->name('items.skip');
    Route::delete('/items/{simpleItem}', [SimpleItemController::class, 'destroy'])->name('items.destroy');

    // A rough picture of what is in, feeding the suggestion ranker's use-up
    // boost alongside the week's use-by windows.
    Route::get('/pantry', [PantryController::class, 'index'])->name('pantry');
    Route::post('/pantry', [PantryController::class, 'store'])->name('pantry.store');
    Route::post('/pantry/{flag}', [PantryController::class, 'update'])->name('pantry.update');
    Route::post('/pantry/{flag}/gone', [PantryController::class, 'markGone'])->name('pantry.gone');
    Route::post('/pantry/{flag}/restock', [PantryController::class, 'restock'])->name('pantry.restock');

    Route::get('/grocery', [GroceryController::class, 'index'])->name('grocery');
    Route::post('/grocery', [GroceryController::class, 'store'])->name('grocery.store');
    Route::post('/grocery/{item}/toggle', [GroceryController::class, 'toggle'])->name('grocery.toggle');
    Route::delete('/grocery/{item}', [GroceryController::class, 'destroy'])->name('grocery.destroy');
    Route::post('/grocery/{item}/stocked', [GroceryController::class, 'markStocked'])->name('grocery.stocked');
    Route::post('/grocery/{item}/aisle', [GroceryController::class, 'setAisle'])->name('grocery.aisle');
    Route::post('/grocery/{item}/quantity', [GroceryController::class, 'updateQuantity'])->name('grocery.quantity');
    Route::post('/grocery/clear-purchased', [GroceryController::class, 'clearPurchased'])->name('grocery.clear');

    // "Try something new" — recipes from outside the household's own library.
    Route::get('/discover', [DiscoverController::class, 'index'])->name('discover');
    Route::post('/discover', [DiscoverController::class, 'store'])->name('discover.store');

    Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes');
    // Declared before the {recipe} route, or "new" is read as a recipe id.
    Route::get('/recipes/new', [RecipeController::class, 'create'])->name('recipes.create');
    Route::post('/recipes', [RecipeController::class, 'store'])->name('recipes.store');
    Route::get('/recipes/{recipe}/edit', [RecipeController::class, 'edit'])->name('recipes.edit');
    Route::put('/recipes/{recipe}', [RecipeController::class, 'update'])->name('recipes.update');
    Route::delete('/recipes/{recipe}', [RecipeController::class, 'destroy'])->name('recipes.destroy');
    Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])->name('recipes.show');
    Route::post('/recipes/{recipe}/rate', [RecipeController::class, 'rate'])->name('recipes.rate');
    Route::post('/recipes/{recipe}/cooked', [RecipeController::class, 'markCooked'])->name('recipes.cooked');
    Route::post('/recipes/{recipe}/import', [RecipeController::class, 'importDetails'])->name('recipes.import');

    // Spec 4.7's manual fallback, plus the photo capture that shares the screen.
    Route::post('/recipes/{recipe}/ingredients', [RecipeIngredientController::class, 'store'])
        ->name('recipes.ingredients.store');
    Route::post('/recipes/{recipe}/ingredients/bulk', [RecipeIngredientController::class, 'storeBulk'])
        ->name('recipes.ingredients.bulk');
    Route::delete('/recipes/{recipe}/ingredients/{ingredient}', [RecipeIngredientController::class, 'destroy'])
        ->name('recipes.ingredients.destroy');
    Route::post('/recipes/{recipe}/base-servings', [RecipeIngredientController::class, 'updateServings'])
        ->name('recipes.servings');
    Route::post('/recipes/{recipe}/instructions', [RecipeIngredientController::class, 'updateInstructions'])
        ->name('recipes.instructions');
    Route::post('/recipes/{recipe}/photo', [RecipeIngredientController::class, 'storePhoto'])
        ->name('recipes.photo.store');
    Route::delete('/recipes/{recipe}/photo', [RecipeIngredientController::class, 'destroyPhoto'])
        ->name('recipes.photo.destroy');
});
