<?php

namespace Tests\Feature;

use App\Http\Services\RecipeIngredientSync;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end coverage of the eight functional requirements: profiles,
 * ingredient search, the cuisine map, guided cooking, the planner, reviews,
 * nutrition and the shopping list.
 */
class FridgeToForkFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\IngredientSeeder::class);

        $this->user = User::create([
            'name' => 'Test Cook',
            'username' => 'testcook',
            'email' => 'cook@example.test',
            'password' => Hash::make('Password123!'),
            'skill_level' => 'advanced',
            'household_size' => 2,
        ]);
    }

    private function makeRecipe(array $attributes = []): Recipe
    {
        $recipe = Recipe::create(array_merge([
            'user_id' => $this->user->id,
            'title' => 'Tomato Pasta',
            'description' => 'A simple weeknight pasta.',
            'ingredients' => ['200 g pasta', '2 tomatoes', '1 tbsp olive oil', '1 tsp salt'],
            'instructions' => ['Boil the pasta for 9 minutes.', 'Toss everything together.'],
            'cuisine_code' => 'IT',
            'difficulty' => 'beginner',
            'prep_minutes' => 5,
            'cook_minutes' => 15,
            'servings' => 2,
            'diet_tags' => ['vegetarian'],
        ], $attributes));

        return app(RecipeIngredientSync::class)->sync($recipe);
    }

    private function stockFridge(array $names): void
    {
        foreach ($names as $name) {
            PantryItem::create([
                'user_id' => $this->user->id,
                'ingredient_id' => Ingredient::resolve($name)->id,
            ]);
        }
    }

    // ── Requirement 1: Account & Profiles ────────────────────────────────

    public function test_a_cook_can_save_dietary_preferences_and_skill_level(): void
    {
        $response = $this->actingAs($this->user)->putJson('/api/profile', [
            'skill_level' => 'intermediate',
            'household_size' => 4,
            'dietary_preferences' => ['vegetarian', 'gluten-free'],
            'allergies' => ['Peanuts'],
        ]);

        $response->assertOk();
        $this->user->refresh();

        $this->assertSame('intermediate', $this->user->skill_level);
        $this->assertSame(4, $this->user->household_size);
        $this->assertSame(['vegetarian', 'gluten-free'], $this->user->dietary_preferences);
    }

    public function test_an_unknown_diet_tag_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/profile', ['dietary_preferences' => ['carnivore-only']])
            ->assertStatus(422);
    }

    // ── Requirement 2: Ingredient-Based Search ───────────────────────────

    public function test_saving_a_recipe_indexes_its_ingredients(): void
    {
        $recipe = $this->makeRecipe();

        $this->assertEqualsCanonicalizing(
            ['Pasta', 'Tomato', 'Olive Oil', 'Salt'],
            $recipe->ingredientRecords->pluck('name')->all()
        );
    }

    public function test_search_ranks_recipes_by_how_much_of_the_fridge_they_use(): void
    {
        $this->makeRecipe(); // 4 ingredients
        $this->makeRecipe([
            'title' => 'Chicken Rice',
            'ingredients' => ['300 g chicken breast', '200 g rice', '1 onion', '1 tsp turmeric'],
            'diet_tags' => [],
        ]);

        $response = $this->postJson('/api/pantry/search', [
            'ingredients' => ['pasta', 'tomato', 'olive oil', 'salt'],
            'max_missing' => 4,
        ]);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame('Tomato Pasta', $data[0]['recipe']['title']);
        $this->assertSame(100, $data[0]['match_percent']);
        $this->assertSame([], $data[0]['missing']);
    }

    public function test_search_reports_what_is_missing(): void
    {
        $this->makeRecipe();

        $data = $this->postJson('/api/pantry/search', [
            'ingredients' => ['pasta', 'salt'],
            'max_missing' => 4,
        ])->json('data');

        $this->assertSame(50, $data[0]['match_percent']);
        $this->assertEqualsCanonicalizing(
            ['Tomato', 'Olive Oil'],
            array_column($data[0]['missing'], 'name')
        );
    }

    public function test_max_missing_filters_out_distant_matches(): void
    {
        $this->makeRecipe();

        $data = $this->postJson('/api/pantry/search', [
            'ingredients' => ['pasta'],
            'max_missing' => 1,
        ])->json('data');

        $this->assertSame([], $data);
    }

    public function test_a_signed_in_cook_can_search_straight_from_their_fridge(): void
    {
        $this->makeRecipe();
        $this->stockFridge(['Pasta', 'Tomato', 'Olive Oil', 'Salt']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/pantry/search', ['use_pantry' => true]);

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.cook_now'));
    }

    public function test_dietary_preferences_exclude_recipes_that_do_not_carry_the_tag(): void
    {
        $this->makeRecipe(['title' => 'Meaty Pasta', 'diet_tags' => []]);
        $this->user->update(['dietary_preferences' => ['vegetarian']]);
        $this->stockFridge(['Pasta', 'Tomato', 'Olive Oil', 'Salt']);

        $data = $this->actingAs($this->user)
            ->postJson('/api/pantry/search', ['use_pantry' => true])
            ->json('data');

        $this->assertSame([], $data);
    }

    public function test_an_allergy_hides_any_recipe_containing_that_ingredient(): void
    {
        $this->makeRecipe();
        $this->user->update(['allergies' => ['Tomato']]);
        $this->stockFridge(['Pasta', 'Tomato', 'Olive Oil', 'Salt']);

        $data = $this->actingAs($this->user)
            ->postJson('/api/pantry/search', ['use_pantry' => true])
            ->json('data');

        $this->assertSame([], $data);
    }

    public function test_plural_and_singular_ingredient_names_match(): void
    {
        $this->makeRecipe(['ingredients' => ['3 tomatoes', '1 tsp salt']]);

        $data = $this->postJson('/api/pantry/search', [
            'ingredients' => ['tomato', 'salt'],
        ])->json('data');

        $this->assertSame(100, $data[0]['match_percent']);
    }

    // ── Requirement 3: Cuisine Map Explorer ──────────────────────────────

    public function test_the_map_reports_a_recipe_count_per_country(): void
    {
        $this->makeRecipe();

        $response = $this->getJson('/api/cuisines');
        $response->assertOk();

        $italy = collect($response->json('data'))->firstWhere('code', 'IT');

        $this->assertSame(1, $italy['recipe_count']);
        $this->assertSame('Europe', $italy['region']);
        $this->assertSame(1, $response->json('meta.countries_with_recipes'));
    }

    public function test_a_country_page_lists_its_recipes(): void
    {
        $this->makeRecipe();

        $this->getJson('/api/cuisines/IT')
            ->assertOk()
            ->assertJsonPath('meta.name', 'Italy')
            ->assertJsonCount(1, 'data');
    }

    public function test_a_cuisine_adjective_resolves_to_a_country(): void
    {
        $this->makeRecipe();

        $this->getJson('/api/cuisines/italian')->assertOk()->assertJsonPath('meta.code', 'IT');
        $this->getJson('/api/cuisines/atlantis')->assertNotFound();
    }

    // ── Requirement 4: Guided Cooking Mode ───────────────────────────────

    public function test_cook_mode_returns_timed_steps_and_scaled_ingredients(): void
    {
        $recipe = $this->makeRecipe();

        $response = $this->getJson("/api/recipes/{$recipe->id}/cook?servings=4");
        $response->assertOk();

        $this->assertEquals(2.0, $response->json('data.scale'));
        $this->assertSame(540, $response->json('data.steps.0.timer_seconds'));

        $pasta = collect($response->json('data.ingredients'))->firstWhere('name', 'Pasta');
        $this->assertEquals(400.0, $pasta['quantity']);
    }

    public function test_cook_mode_marks_ingredients_already_in_the_fridge(): void
    {
        $recipe = $this->makeRecipe();
        $this->stockFridge(['Pasta']);

        $ingredients = $this->actingAs($this->user)
            ->getJson("/api/recipes/{$recipe->id}/cook")
            ->json('data.ingredients');

        $this->assertTrue(collect($ingredients)->firstWhere('name', 'Pasta')['in_pantry']);
        $this->assertFalse(collect($ingredients)->firstWhere('name', 'Tomato')['in_pantry']);
    }

    // ── Requirement 5: Favourites & Meal Planner ─────────────────────────

    public function test_a_recipe_can_be_planned_into_a_day_and_slot(): void
    {
        $recipe = $this->makeRecipe();
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->actingAs($this->user)->postJson('/api/meal-plan', [
            'recipe_id' => $recipe->id,
            'plan_date' => $monday,
            'meal_slot' => 'dinner',
        ])->assertCreated();

        $response = $this->actingAs($this->user)->getJson('/api/meal-plan');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($monday, $response->json('meta.week_start'));
    }

    public function test_planning_the_same_slot_twice_replaces_rather_than_duplicates(): void
    {
        $first = $this->makeRecipe();
        $second = $this->makeRecipe(['title' => 'Second Dinner']);
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

        foreach ([$first, $second] as $recipe) {
            $this->actingAs($this->user)->postJson('/api/meal-plan', [
                'recipe_id' => $recipe->id,
                'plan_date' => $monday,
                'meal_slot' => 'dinner',
            ])->assertCreated();
        }

        $this->actingAs($this->user)->getJson('/api/meal-plan')->assertJsonCount(1, 'data');
    }

    public function test_the_planner_totals_calories_per_day(): void
    {
        $recipe = $this->makeRecipe();
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->actingAs($this->user)->postJson('/api/meal-plan', [
            'recipe_id' => $recipe->id,
            'plan_date' => $monday,
            'meal_slot' => 'dinner',
            'servings' => 2,
        ]);

        $totals = $this->actingAs($this->user)->getJson('/api/meal-plan')->json('meta.nutrition_by_day');

        $this->assertGreaterThan(0, $totals[$monday]['calories']);
    }

    public function test_one_cook_cannot_delete_another_cooks_plan(): void
    {
        $recipe = $this->makeRecipe();
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

        $entryId = $this->actingAs($this->user)->postJson('/api/meal-plan', [
            'recipe_id' => $recipe->id,
            'plan_date' => $monday,
            'meal_slot' => 'dinner',
        ])->json('data.id');

        $intruder = User::create([
            'name' => 'Someone Else',
            'username' => 'intruder',
            'email' => 'intruder@example.test',
            'password' => Hash::make('Password123!'),
        ]);

        $this->actingAs($intruder)->deleteJson("/api/meal-plan/{$entryId}")->assertForbidden();
    }

    // ── Requirement 7: Nutrition Insights ────────────────────────────────

    public function test_nutrition_is_calculated_per_serving_when_a_recipe_is_saved(): void
    {
        $recipe = $this->makeRecipe();

        $this->assertNotNull($recipe->nutrition);
        $this->assertSame('serving', $recipe->nutrition['per']);
        $this->assertGreaterThan(0, $recipe->nutrition['calories']);
        $this->assertEquals(1.0, $recipe->nutrition['coverage']);
    }

    public function test_doubling_the_servings_halves_the_per_serving_calories(): void
    {
        $two = $this->makeRecipe(['servings' => 2]);
        $four = $this->makeRecipe(['title' => 'Bigger Batch', 'servings' => 4]);

        $this->assertEqualsWithDelta(
            $two->nutrition['calories'] / 2,
            $four->nutrition['calories'],
            1.0
        );
    }

    // ── Requirement 8: Auto Shopping List ────────────────────────────────

    public function test_the_shopping_list_is_built_from_the_planned_week(): void
    {
        $recipe = $this->makeRecipe();
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->actingAs($this->user)->postJson('/api/meal-plan', [
            'recipe_id' => $recipe->id,
            'plan_date' => $monday,
            'meal_slot' => 'dinner',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/shopping-list/generate', []);
        $response->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Pasta', 'Tomato', 'Olive Oil', 'Salt'],
            collect($response->json('data'))->pluck('name')->all()
        );
    }

    private function planTomatoPasta(): void
    {
        $recipe = $this->makeRecipe();

        $this->actingAs($this->user)->postJson('/api/meal-plan', [
            'recipe_id' => $recipe->id,
            'plan_date' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'meal_slot' => 'dinner',
            'servings' => 2,
        ]);
    }

    private function generateList()
    {
        return collect($this->actingAs($this->user)->postJson('/api/shopping-list/generate', [])->json('data'))
            ->keyBy('name');
    }

    public function test_fridge_items_with_no_recorded_amount_stay_on_the_list_to_check(): void
    {
        $this->planTomatoPasta();
        $this->stockFridge(['Pasta']);

        $pasta = $this->generateList()->get('Pasta');

        // Having some pasta is not the same as having enough of it.
        $this->assertSame('check', $pasta['pantry_status']);
        $this->assertSame(200.0, (float) $pasta['quantity']);
        $this->assertFalse($pasta['is_checked']);
    }

    public function test_only_the_shortfall_is_left_to_buy(): void
    {
        $this->planTomatoPasta();
        PantryItem::create([
            'user_id' => $this->user->id,
            'ingredient_id' => Ingredient::resolve('Pasta')->id,
            'quantity' => 0.05,
            'unit' => 'kg',
        ]);

        $pasta = $this->generateList()->get('Pasta');

        $this->assertSame('partial', $pasta['pantry_status']);
        $this->assertSame(150.0, (float) $pasta['quantity']);
        $this->assertSame(200.0, (float) $pasta['needed_quantity']);
        $this->assertSame('kg', $pasta['pantry_unit']);
    }

    public function test_ingredients_the_fridge_fully_covers_are_ticked_off(): void
    {
        $this->planTomatoPasta();
        PantryItem::create([
            'user_id' => $this->user->id,
            'ingredient_id' => Ingredient::resolve('Pasta')->id,
            'quantity' => 500,
            'unit' => 'g',
        ]);

        $list = $this->generateList();

        $this->assertSame('covered', $list->get('Pasta')['pantry_status']);
        $this->assertTrue($list->get('Pasta')['is_checked']);
        $this->assertNull($list->get('Tomato')['pantry_status']);
        $this->assertFalse($list->get('Tomato')['is_checked']);
    }

    // ── Expiry tracking: use it up first ─────────────────────────────────

    public function test_new_fridge_items_get_an_estimated_expiry_date(): void
    {
        $items = collect($this->actingAs($this->user)->postJson('/api/pantry', ['name' => 'Tomato'])->json('data'));
        $tomato = $items->firstWhere('ingredient.name', 'Tomato');

        $this->assertSame(Carbon::today()->addDays(5)->toDateString(), $tomato['expires_on']);
        $this->assertSame(5, $tomato['days_left']);
        $this->assertSame('ok', $tomato['expiry_status']);

        // Cupboard staples get no automatic date.
        $items = collect($this->actingAs($this->user)->postJson('/api/pantry', ['name' => 'Salt'])->json('data'));
        $this->assertNull($items->firstWhere('ingredient.name', 'Salt')['expires_on']);
    }

    public function test_cooks_can_record_quantity_and_expiry(): void
    {
        $this->stockFridge(['Milk']);
        $item = $this->user->pantryItems()->first();

        $response = $this->actingAs($this->user)->patchJson("/api/pantry/{$item->id}", [
            'quantity' => 1.5,
            'unit' => 'l',
            'expires_on' => Carbon::today()->addDay()->toDateString(),
        ])->assertOk();

        $milk = collect($response->json('data'))->first();
        $this->assertSame(1.5, (float) $milk['quantity']);
        $this->assertSame('soon', $milk['expiry_status']);

        $intruder = User::create([
            'name' => 'Intruder', 'username' => 'intruder2', 'email' => 'intruder2@example.test',
            'password' => Hash::make('Password123!'),
        ]);
        $this->actingAs($intruder)->patchJson("/api/pantry/{$item->id}", ['quantity' => 0])->assertForbidden();
    }

    public function test_recipes_using_soon_to_expire_items_are_ranked_first(): void
    {
        $pasta = $this->makeRecipe();
        $omelette = $this->makeRecipe([
            'title' => 'Spinach Omelette',
            'ingredients' => ['3 eggs', '1 handful spinach', '1 tsp salt'],
        ]);

        foreach (['Pasta', 'Tomato', 'Olive Oil', 'Salt', 'Egg', 'Spinach'] as $name) {
            PantryItem::create([
                'user_id' => $this->user->id,
                'ingredient_id' => Ingredient::resolve($name)->id,
                'expires_on' => $name === 'Spinach' ? Carbon::today()->addDay() : null,
            ]);
        }

        $response = $this->actingAs($this->user)->postJson('/api/pantry/search', ['use_pantry' => true])->assertOk();

        $this->assertSame($omelette->id, $response->json('data.0.recipe.id'));
        $this->assertSame(['Spinach'], $response->json('data.0.uses_expiring'));
        $this->assertSame([], $response->json('data.1.uses_expiring'));
        $this->assertSame('Spinach', $response->json('meta.use_soon.0.name'));
        $this->assertSame($pasta->id, $response->json('data.1.recipe.id'));
    }

    public function test_the_same_ingredient_across_two_meals_is_added_up_once(): void
    {
        $recipe = $this->makeRecipe();
        $monday = Carbon::today()->startOfWeek(Carbon::MONDAY);

        foreach (['lunch', 'dinner'] as $slot) {
            $this->actingAs($this->user)->postJson('/api/meal-plan', [
                'recipe_id' => $recipe->id,
                'plan_date' => $monday->toDateString(),
                'meal_slot' => $slot,
                'servings' => 2,
            ]);
        }

        $items = collect($this->actingAs($this->user)->postJson('/api/shopping-list/generate', [])->json('data'));
        $pasta = $items->firstWhere('name', 'Pasta');

        $this->assertCount(4, $items);
        $this->assertSame(400.0, (float) $pasta['quantity']);
        $this->assertCount(1, $pasta['recipe_titles']);
    }

    public function test_items_can_be_ticked_off_and_cleared(): void
    {
        $item = $this->actingAs($this->user)
            ->postJson('/api/shopping-list', ['name' => 'Milk', 'quantity' => 1, 'unit' => 'l'])
            ->assertCreated()
            ->json('data.0');

        $this->actingAs($this->user)
            ->putJson("/api/shopping-list/{$item['id']}", ['is_checked' => true])
            ->assertOk()
            ->assertJsonPath('meta.remaining', 0);

        $this->actingAs($this->user)
            ->postJson('/api/shopping-list/clear', ['checked_only' => true])
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_the_shareable_text_prints_whole_quantities_without_dropping_digits(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/shopping-list', ['name' => 'Chicken Breast', 'quantity' => 600, 'unit' => 'g']);

        $text = $this->actingAs($this->user)->getJson('/api/shopping-list')->json('meta.shareable_text');

        $this->assertStringContainsString('600 g Chicken Breast', $text);
        $this->assertStringNotContainsString('6 g Chicken Breast', $text);
    }

    // ── Cross-cutting ────────────────────────────────────────────────────

    public function test_water_never_counts_as_a_missing_ingredient(): void
    {
        $recipe = $this->makeRecipe([
            'ingredients' => ['200 g pasta', '750 ml water', '1 tsp salt'],
        ]);

        $this->assertNotContains('Water', $recipe->ingredientRecords->pluck('name')->all());
    }

    public function test_the_library_can_be_filtered_by_cuisine_and_diet(): void
    {
        $this->makeRecipe();
        $this->makeRecipe([
            'title' => 'Tokyo Bowl',
            'cuisine_code' => 'JP',
            'diet_tags' => [],
        ]);

        $this->getJson('/api/recipes?cuisine=IT')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/recipes?diets=vegetarian')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/recipes?max_minutes=5')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pantry_items_belong_to_their_owner(): void
    {
        $this->stockFridge(['Pasta']);
        $itemId = $this->user->pantryItems()->first()->id;

        $intruder = User::create([
            'name' => 'Someone Else',
            'username' => 'intruder2',
            'email' => 'intruder2@example.test',
            'password' => Hash::make('Password123!'),
        ]);

        $this->actingAs($intruder)->deleteJson("/api/pantry/{$itemId}")->assertForbidden();
        $this->actingAs($this->user)->deleteJson("/api/pantry/{$itemId}")->assertOk();
    }
}
