<?php

namespace Tests\Feature;

use App\Support\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The chatbot and the fridge photo scan running on OpenAI, with the OpenAI
 * API faked so no real key or network call is needed.
 */
class OpenAiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const COMPLETIONS = 'https://api.openai.com/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai.provider' => null,
            'services.anthropic.key' => null,
            'services.openai.key' => 'sk-test-openai',
            'services.openai.model' => 'gpt-4o-mini',
        ]);
        $this->seed();
    }

    private function completion(array $message, string $finish = 'stop'): array
    {
        return ['choices' => [['index' => 0, 'finish_reason' => $finish, 'message' => $message + ['role' => 'assistant']]]];
    }

    public function test_the_provider_follows_the_configured_keys(): void
    {
        $this->assertSame(AiProvider::OPENAI, AiProvider::current());

        config(['services.anthropic.key' => 'sk-ant-test']);
        $this->assertSame(AiProvider::ANTHROPIC, AiProvider::current());

        config(['services.ai.provider' => 'openai']);
        $this->assertSame(AiProvider::OPENAI, AiProvider::current());

        config(['services.openai.key' => null]);
        $this->assertNull(AiProvider::current());
    }

    public function test_the_chatbot_answers_through_openai_using_the_recipe_tools(): void
    {
        Http::fake([
            self::COMPLETIONS => Http::sequence()
                ->push($this->completion([
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'find_recipes_by_ingredients',
                            'arguments' => json_encode(['ingredients' => ['eggs', 'onion', 'potato']]),
                        ],
                    ]],
                ], 'tool_calls'))
                ->push($this->completion(['content' => 'Try the Spanish Tortilla — you only need olive oil and salt.'])),
        ]);

        $response = $this->postJson('/api/chat', [
            'messages' => [['role' => 'user', 'content' => 'I have eggs, onion and potato']],
        ])->assertOk();

        $response->assertJsonPath('mode', 'ai');
        $response->assertJsonPath('recipes.0.title', 'Spanish Tortilla');

        $requests = Http::recorded();
        $this->assertCount(2, $requests);

        /** @var Request $first */
        $first = $requests[0][0];
        $this->assertSame('Bearer sk-test-openai', $first->header('Authorization')[0]);
        $this->assertSame('gpt-4o-mini', $first['model']);
        $this->assertSame('system', $first['messages'][0]['role']);
        $this->assertSame('find_recipes_by_ingredients', $first['tools'][0]['function']['name']);

        // The second call carries the tool result back to the model.
        $second = $requests[1][0];
        $toolMessage = collect($second['messages'])->firstWhere('role', 'tool');
        $this->assertSame('call_1', $toolMessage['tool_call_id']);
        $this->assertStringContainsString('Spanish Tortilla', $toolMessage['content']);
    }

    public function test_an_openai_failure_falls_back_to_the_built_in_assistant(): void
    {
        Http::fake([self::COMPLETIONS => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

        $this->postJson('/api/chat', [
            'messages' => [['role' => 'user', 'content' => 'I have eggs, onion and potato']],
        ])
            ->assertOk()
            ->assertJsonPath('mode', 'assistant')
            ->assertJsonPath('recipes.0.title', 'Spanish Tortilla');
    }

    public function test_the_fridge_photo_scan_works_through_openai(): void
    {
        Http::fake([
            self::COMPLETIONS => Http::response($this->completion([
                'content' => json_encode(['ingredients' => [
                    ['name' => 'tomatoes', 'confidence' => 'high'],
                    ['name' => 'Milk', 'confidence' => 'medium'],
                    ['name' => 'dragon fruit', 'confidence' => 'low'],
                ]]),
            ])),
        ]);

        $photo = UploadedFile::fake()->createWithContent('fridge.jpg', base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
        ));

        $response = $this->post('/api/pantry/scan', ['photo' => $photo], ['Accept' => 'application/json'])->assertOk();

        $this->assertSame(['Tomato', 'Milk', 'Dragon fruit'], array_column($response->json('data'), 'name'));
        $this->assertSame([true, true, false], array_column($response->json('data'), 'known'));

        $sent = Http::recorded()[0][0];
        $this->assertSame('json_schema', $sent['response_format']['type']);
        $this->assertStringStartsWith('data:image/', $sent['messages'][0]['content'][1]['image_url']['url']);
    }

    public function test_the_photo_scan_reports_a_refusal_clearly(): void
    {
        Http::fake([self::COMPLETIONS => Http::response($this->completion(['content' => null, 'refusal' => 'I cannot help with that.']))]);

        $photo = UploadedFile::fake()->create('fridge.jpg', 10, 'image/jpeg');

        $this->post('/api/pantry/scan', ['photo' => $photo], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
