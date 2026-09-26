<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\Review;
use App\Models\Tip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Admin → Users → Delete User must work for a cook with real activity. */
class AdminDeleteUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_delete_a_cook_who_received_tips_and_reviews(): void
    {
        $admin = User::factory()->create(['username' => 'boss', 'is_admin' => true]);
        $cook = User::factory()->create(['username' => 'cook']);
        $fan = User::factory()->create(['username' => 'fan']);

        $recipe = Recipe::create([
            'user_id' => $cook->id,
            'title' => 'Dal',
            'description' => 'Red lentil dal.',
            'ingredients' => ['200 g red lentils'],
            'instructions' => ['Boil the lentils.'],
            'servings' => 2,
            'difficulty' => 'beginner',
        ]);
        Review::create(['user_id' => $fan->id, 'recipe_id' => $recipe->id, 'rating' => 5, 'comment' => 'Lovely']);
        $fan->favorites()->attach($recipe->id);
        Tip::create(['sender_id' => $fan->id, 'recipient_id' => $cook->id, 'amount' => 2, 'message' => 'Thanks']);
        Tip::create(['sender_id' => $cook->id, 'recipient_id' => $fan->id, 'amount' => 1, 'message' => 'Back at you']);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$cook->id}")->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $cook->id]);
        $this->assertDatabaseMissing('recipes', ['id' => $recipe->id]);
        $this->assertDatabaseCount('tips', 0);
        $this->assertDatabaseHas('users', ['id' => $fan->id]);
    }

    public function test_an_admin_cannot_delete_another_admin_or_themselves(): void
    {
        $admin = User::factory()->create(['username' => 'boss', 'is_admin' => true]);
        $other = User::factory()->create(['username' => 'boss2', 'is_admin' => true]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/admin/users/{$admin->id}")->assertForbidden();
        $this->deleteJson("/api/admin/users/{$other->id}")->assertForbidden();
    }
}
