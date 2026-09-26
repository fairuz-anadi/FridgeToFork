<?php

namespace App\Http\Controllers;

use Anthropic\Client;
use App\Exceptions\AiRequestDeclined;
use App\Models\Ingredient;
use App\Support\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Snap your fridge" — the configured AI provider (Claude or OpenAI) reads a
 * photo of a fridge or shelf and lists the ingredients it can see, matched to
 * the app's ingredient vocabulary. The cook confirms the list before anything
 * is added to their fridge.
 */
class PantryScanController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $provider = AiProvider::current();

        if (!$provider) {
            return response()->json([
                'message' => 'Photo scanning is not switched on for this site yet. Add ingredients by typing instead.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        set_time_limit(120);
        $photo = $request->file('photo');
        $prompt = $this->prompt(Ingredient::orderBy('name')->pluck('name')->implode(', '));

        try {
            $json = $provider === AiProvider::OPENAI
                ? $this->askOpenAi($photo, $prompt)
                : $this->askClaude($photo, $prompt);
        } catch (AiRequestDeclined) {
            return response()->json([
                'message' => "We couldn't read ingredients from that photo. Try a clearer picture of your fridge.",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            Log::warning('Fridge photo scan failed: ' . $e->getMessage());

            return response()->json([
                'message' => "We couldn't scan that photo right now. Please try again, or type your ingredients.",
            ], Response::HTTP_BAD_GATEWAY);
        }

        $found = collect(json_decode($json, true)['ingredients'] ?? []);

        // Match each name to the vocabulary; unknown names are still offered,
        // and adding one creates the ingredient just like typing it would.
        $items = $found
            ->map(function (array $item) {
                $ingredient = Ingredient::lookup((string) ($item['name'] ?? ''));

                return [
                    'name' => $ingredient?->name ?? ucfirst(trim((string) ($item['name'] ?? ''))),
                    'known' => (bool) $ingredient,
                    'confidence' => $item['confidence'] ?? 'medium',
                ];
            })
            ->filter(fn (array $item) => $item['name'] !== '')
            ->unique(fn (array $item) => mb_strtolower($item['name']))
            ->values();

        return response()->json([
            'data' => $items,
            'message' => $items->isEmpty()
                ? "We couldn't spot any ingredients in that photo."
                : "Found {$items->count()} ingredients. Untick anything that's wrong, then add them.",
        ]);
    }

    private function prompt(string $knownNames): string
    {
        return "List the food ingredients you can see in this photo of someone's fridge, pantry or kitchen counter, so they can be added to a recipe app.\n\n"
            . "When an item matches one of these ingredient names, use that exact name: {$knownNames}.\n\n"
            . 'Be thorough: look at every shelf, drawer, door and container and list every distinct food you can recognise — '
            . 'vegetables, fruit, fresh herbs, mushrooms, dairy, eggs, meat, fish, drinks and sauces. '
            . 'List each kind once, however many pieces there are. '
            . 'For anything not in the list, use a short everyday name (for example "spinach", not "a bag of baby spinach leaves"). '
            . 'Use "medium" or "low" confidence when you are unsure (for example an unlabelled bottle), and skip anything that is not food. '
            . 'If the photo shows no food at all, return an empty list.';
    }

    /** JSON schema both providers must answer with. */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ingredients' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        ],
                        'required' => ['name', 'confidence'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['ingredients'],
            'additionalProperties' => false,
        ];
    }

    private function askClaude(UploadedFile $photo, string $prompt): string
    {
        $client = new Client(
            apiKey: config('services.anthropic.key'),
            baseUrl: config('services.anthropic.base_url'),
        );

        $message = $client->beta->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: 4000,
            messages: [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'mediaType' => $photo->getMimeType(),
                            'data' => base64_encode($photo->get()),
                        ],
                    ],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
            outputConfig: [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
            ],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );

        if ($message->stopReason === 'refusal') {
            throw new AiRequestDeclined();
        }

        $json = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json .= $block->text;
            }
        }

        return $json;
    }

    private function askOpenAi(UploadedFile $photo, string $prompt): string
    {
        $message = Http::withToken(config('services.openai.key'))
            ->timeout(60)
            ->post(rtrim(config('services.openai.base_url'), '/') . '/chat/completions', [
                'model' => config('services.openai.model'),
                'max_completion_tokens' => 1500,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:' . $photo->getMimeType() . ';base64,' . base64_encode($photo->get()),
                                // Busy shelves need the full-resolution pass to spot small items.
                                'detail' => 'high',
                            ],
                        ],
                    ],
                ]],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => ['name' => 'fridge_ingredients', 'strict' => true, 'schema' => $this->schema()],
                ],
            ])
            ->throw()
            ->json('choices.0.message');

        if (!empty($message['refusal'])) {
            throw new AiRequestDeclined();
        }

        return (string) ($message['content'] ?? '');
    }
}
