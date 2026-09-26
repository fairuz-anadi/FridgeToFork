<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CookModeController;
use App\Http\Controllers\CuisineController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\PantryController;
use App\Http\Controllers\PantryScanController;
use App\Http\Controllers\PantrySearchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ShoppingListController;
use App\Http\Controllers\TipController;
use Illuminate\Support\Facades\Route;

Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('auth/google', [AuthController::class, 'google'])->middleware('throttle:10,1');
Route::get('auth/google/callback', [AuthController::class, 'googleCallback']);

Route::get('categories', [CategoryController::class, 'index']);
Route::get('recipes', [RecipeController::class, 'index']);
Route::get('recipes/{recipe}', [RecipeController::class, 'show']);
Route::get('recipe-images/{path}', [RecipeController::class, 'image'])->where('path', '.*');
Route::get('leaderboards', [DashboardController::class, 'leaderboards']);
Route::get('users/{user}/tips', [TipController::class, 'show']);
Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:5,1');

// Ingredient-Based Search — works signed out too, using ad-hoc ingredients.
Route::get('ingredients', [IngredientController::class, 'index']);
Route::get('ingredients/aisles', [IngredientController::class, 'aisles']);
Route::post('pantry/search', PantrySearchController::class);
// Snap your fridge — works signed out too; the page decides where results go.
Route::post('pantry/scan', PantryScanController::class)->middleware('throttle:6,1');

// Recipe chatbot — works signed out too; uses the fridge when signed in.
Route::post('chat', ChatController::class)->middleware('throttle:15,1');

// Cuisine Map Explorer
Route::get('cuisines', [CuisineController::class, 'index']);
Route::get('cuisines/{code}', [CuisineController::class, 'show']);

// Guided Cooking Mode
Route::get('recipes/{recipe}/cook', CookModeController::class);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('dashboard', [DashboardController::class, 'user']);

    Route::post('recipes', [RecipeController::class, 'store']);
    Route::put('recipes/{recipe}', [RecipeController::class, 'update']);
    Route::post('recipes/{recipe}', [RecipeController::class, 'update']);
    Route::delete('recipes/{recipe}', [RecipeController::class, 'destroy']);
    Route::post('recipes/{recipe}/favorite', [FavoriteController::class, 'store']);
    Route::delete('recipes/{recipe}/favorite', [FavoriteController::class, 'destroy']);
    Route::post('recipes/{recipe}/reviews', [ReviewController::class, 'store']);
    Route::delete('recipes/{recipe}/reviews/{review}', [ReviewController::class, 'destroy']);
    Route::post('tips', [TipController::class, 'store']);

    // Account & Profiles — dietary preferences and skill level
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);

    // What's in my fridge
    Route::get('pantry', [PantryController::class, 'index']);
    Route::post('pantry', [PantryController::class, 'store']);
    Route::put('pantry', [PantryController::class, 'sync']);
    Route::patch('pantry/{pantryItem}', [PantryController::class, 'update']);
    Route::delete('pantry/{pantryItem}', [PantryController::class, 'destroy']);

    // Meal planner
    Route::get('meal-plan', [MealPlanController::class, 'index']);
    Route::post('meal-plan', [MealPlanController::class, 'store']);
    Route::put('meal-plan/{mealPlanEntry}', [MealPlanController::class, 'update']);
    Route::delete('meal-plan/{mealPlanEntry}', [MealPlanController::class, 'destroy']);

    // Auto shopping list
    Route::get('shopping-list', [ShoppingListController::class, 'index']);
    Route::post('shopping-list', [ShoppingListController::class, 'store']);
    Route::post('shopping-list/generate', [ShoppingListController::class, 'generate']);
    Route::put('shopping-list/{shoppingListItem}', [ShoppingListController::class, 'update']);
    Route::delete('shopping-list/{shoppingListItem}', [ShoppingListController::class, 'destroy']);
    Route::post('shopping-list/clear', [ShoppingListController::class, 'clear']);

    Route::middleware('admin')->group(function () {
        Route::get('admin/dashboard', [AdminController::class, 'dashboard']);
        Route::delete('admin/recipes/{recipe}', [AdminController::class, 'deleteRecipe']);
        Route::delete('admin/users/{user}', [AdminController::class, 'deleteUser']);
        Route::delete('admin/reviews/{review}', [AdminController::class, 'deleteReview']);
        Route::delete('admin/contacts/{contact}', [AdminController::class, 'deleteContact']);
    });
});
