<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The recipe chatbot's built-in assistant (used when no Anthropic API key is
 * configured) and the endpoint's validation.
 */
class ChatAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => null, 'services.openai.key' => null]);
        $this->seed();
    }

    private function ask(string $question)
    {
        return $this->postJson('/api/chat', [
            'messages' => [
                ['role' => 'assistant', 'content' => 'Hi!'],
                ['role' => 'user', 'content' => $question],
            ],
        ]);
    }

    public function test_ingredients_in_the_message_return_ranked_recipes(): void
    {
        $response = $this->ask('I have eggs, onion and potato')->assertOk();

        $response->assertJsonPath('mode', 'assistant');
        $this->assertStringContainsString('Spanish Tortilla', $response->json('reply'));
        $this->assertSame('Spanish Tortilla', $response->json('recipes.0.title'));
        $this->assertNotNull($response->json('recipes.0.match_percent'));
    }

    public function test_cuisine_diet_and_time_filters_are_understood(): void
    {
        $response = $this->ask('something quick and vegetarian')->assertOk();

        $this->assertNotEmpty($response->json('recipes'));
        foreach ($response->json('recipes') as $recipe) {
            $this->assertLessThanOrEqual(30, $recipe['total_minutes']);
        }

        $italian = $this->ask('any Italian dishes?')->assertOk();
        $this->assertSame(['Italy'], array_values(array_unique(array_column($italian->json('recipes'), 'cuisine'))));
    }

    public function test_how_to_questions_get_app_help(): void
    {
        $this->ask('how do I make a shopping list?')
            ->assertOk()
            ->assertJsonPath('recipes', [])
            ->assertJsonFragment(['mode' => 'assistant']);

        $this->assertStringContainsString('Rebuild from meal plan', $this->ask('how do I make a shopping list?')->json('reply'));
    }

    public function test_the_conversation_must_end_with_a_user_message(): void
    {
        $this->postJson('/api/chat', ['messages' => [['role' => 'assistant', 'content' => 'Hi!']]])
            ->assertStatus(422);

        $this->postJson('/api/chat', ['messages' => [['role' => 'system', 'content' => 'x']]])
            ->assertStatus(422);
    }
}
