<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A signed-in cook with a stocked fridge, a half-filled week and a few
 * reviews, so every screen has something to show on a fresh install.
 */
class DemoCookSeeder extends Seeder
{
    private const FRIDGE = [
        'Onion', 'Garlic', 'Tomato', 'Olive Oil', 'Salt', 'Black Pepper',
        'Pasta', 'Rice', 'Egg', 'Chicken Breast', 'Lentils', 'Cumin',
        'Turmeric', 'Chilli Powder', 'Vegetable Oil', 'Lemon', 'Ginger',
        'Chopped Tomatoes', 'Parsley', 'Green Chilli', 'Bell Pepper',
    ];

    /**
     * Amounts and days-until-expiry for part of the demo fridge, so the
     * shopping list can show shortfalls and "use it up first" has something
     * to rank. Dates are relative to the day the seeder runs.
     *
     * @var array<string, array{0: float|null, 1: string|null, 2: int|null}>
     */
    private const FRIDGE_DETAILS = [
        'Rice' => [250, 'g', null],
        'Pasta' => [500, 'g', null],
        'Egg' => [4, null, 6],
        'Chicken Breast' => [300, 'g', 1],
        'Lentils' => [400, 'g', null],
        'Parsley' => [1, 'bunch', 1],
        'Green Chilli' => [null, null, 2],
        'Tomato' => [3, null, 3],
        'Lemon' => [2, null, 8],
        'Bell Pepper' => [1, null, 2],
    ];

    public function run(): void
    {
        $demo = User::firstOrCreate(
            ['email' => 'demo@fridgetofork.test'],
            [
                'name' => 'Demo Cook',
                'username' => 'demo',
                'password' => Hash::make('DemoPass123!'),
                'email_verified_at' => now(),
                'skill_level' => 'beginner',
                'household_size' => 2,
                'dietary_preferences' => [],
                'allergies' => [],
                'points' => 20,
            ]
        );

        foreach (self::FRIDGE as $name) {
            $ingredient = Ingredient::where('slug', Ingredient::slugify($name))->first();

            if (!$ingredient) {
                continue;
            }

            [$quantity, $unit, $days] = self::FRIDGE_DETAILS[$name] ?? [null, null, null];

            PantryItem::updateOrCreate(
                ['user_id' => $demo->id, 'ingredient_id' => $ingredient->id],
                [
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'expires_on' => $days === null ? null : Carbon::today()->addDays($days)->toDateString(),
                ]
            );
        }

        $this->seedMealPlan($demo);
        $this->seedFavouritesAndReviews($demo);
    }

    private function seedMealPlan(User $demo): void
    {
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);

        $plan = [
            [0, 'breakfast', 'Shakshuka'],
            [0, 'dinner', 'Spaghetti Aglio e Olio'],
            [1, 'lunch', 'Red Lentil Dal'],
            [1, 'dinner', 'Bengali Chicken Curry'],
            [2, 'dinner', 'Black Bean Tacos'],
            [3, 'dinner', 'Thai Green Curry'],
            [4, 'dinner', 'Chicken Katsu Curry'],
        ];

        foreach ($plan as [$offset, $slot, $title]) {
            $recipe = Recipe::where('title', $title)->first();

            if (!$recipe) {
                continue;
            }

            MealPlanEntry::updateOrCreate(
                [
                    'user_id' => $demo->id,
                    'plan_date' => $monday->copy()->addDays($offset)->toDateString(),
                    'meal_slot' => $slot,
                ],
                [
                    'recipe_id' => $recipe->id,
                    'servings' => 2,
                ]
            );
        }
    }

    private function seedFavouritesAndReviews(User $demo): void
    {
        $favourites = Recipe::whereIn('title', [
            'Spaghetti Aglio e Olio',
            'Red Lentil Dal',
            'Miso Salmon with Greens',
        ])->pluck('id');

        $demo->favorites()->syncWithoutDetaching($favourites);

        $reviews = [
            ['Spaghetti Aglio e Olio', 5, 'Made this on a Tuesday with nothing in the house. Genuinely brilliant.'],
            ['Red Lentil Dal', 5, 'The tempering step makes all the difference — do not skip it.'],
            ['Chicken Katsu Curry', 4, 'Sauce was great. I needed a couple more minutes on the chicken.'],
        ];

        foreach ($reviews as [$title, $rating, $comment]) {
            $recipe = Recipe::where('title', $title)->first();

            if (!$recipe) {
                continue;
            }

            Review::updateOrCreate(
                ['recipe_id' => $recipe->id, 'user_id' => $demo->id],
                ['rating' => $rating, 'comment' => $comment]
            );

            $recipe->update([
                'average_rating' => round((float) $recipe->reviews()->avg('rating'), 2),
            ]);
        }
    }
}
