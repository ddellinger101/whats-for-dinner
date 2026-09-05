<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\GroceryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\RecipeController;
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

    Route::get('/plan', [MealPlanController::class, 'index'])->name('plan');
    Route::get('/plan/{date}/{slot}/add', [MealPlanController::class, 'picker'])->name('plan.picker');
    Route::post('/plan/{date}/{slot}/primary', [MealPlanController::class, 'setPrimary'])->name('plan.primary');
    Route::post('/plan/{date}/{slot}/side', [MealPlanController::class, 'addSide'])->name('plan.side');
    Route::post('/plan/{date}/{slot}/servings', [MealPlanController::class, 'setServings'])->name('plan.servings');
    Route::delete('/plan/component/{component}', [MealPlanController::class, 'removeComponent'])->name('plan.component.remove');

    Route::get('/grocery', [GroceryController::class, 'index'])->name('grocery');
    Route::post('/grocery', [GroceryController::class, 'store'])->name('grocery.store');
    Route::post('/grocery/{item}/toggle', [GroceryController::class, 'toggle'])->name('grocery.toggle');
    Route::delete('/grocery/{item}', [GroceryController::class, 'destroy'])->name('grocery.destroy');
    Route::post('/grocery/{item}/stocked', [GroceryController::class, 'markStocked'])->name('grocery.stocked');
    Route::post('/grocery/clear-purchased', [GroceryController::class, 'clearPurchased'])->name('grocery.clear');

    Route::get('/recipes', [RecipeController::class, 'index'])->name('recipes');
    Route::get('/recipes/{recipe}', [RecipeController::class, 'show'])->name('recipes.show');
    Route::post('/recipes/{recipe}/rate', [RecipeController::class, 'rate'])->name('recipes.rate');
    Route::post('/recipes/{recipe}/cooked', [RecipeController::class, 'markCooked'])->name('recipes.cooked');
});
