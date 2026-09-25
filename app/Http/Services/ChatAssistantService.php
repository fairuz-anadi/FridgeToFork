<?php

namespace App\Http\Services;

use Anthropic\Client;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use App\Support\CuisineCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The FridgeToFork recipe chatbot.
 *
 * When ANTHROPIC_API_KEY is set, Claude answers using tools that read the
 * recipe library, the ingredient matcher and the signed-in cook's fridge.
 * Without a key — or if the API call fails — a rule-based assistant answers
 * from the same data, so the chat always works.
 */
class ChatAssistantService
{
    private const MAX_TOOL_ROUNDS = 5;
    private const MAX_CARDS = 4;

    private const DIETS = ['vegetarian', 'vegan', 'halal', 'gluten-free', 'dairy-free', 'pescatarian', 'high-protein'];

    /** @var array<int, Recipe> recipes the tools returned this turn, keyed by id */
    private array $seen = [];

    public function __construct(private PantryMatchService $matcher)
    {
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{reply: string, recipes: array<int, array<string, mixed>>, mode: string}
     */
    public function reply(array $history, ?User $user): array
    {
        $this->seen = [];

        if (config('services.anthropic.key')) {
            try {
                return $this->replyWithClaude($history, $user);
            } catch (Throwable $e) {
                Log::warning('Chat assistant fell back to the built-in replies: ' . $e->getMessage());
                $this->seen = [];
            }
        }

        return $this->replyBuiltIn((string) end($history)['content'], $user);
    }

    // ------------------------------------------------------------------ Claude

    private function replyWithClaude(array $history, ?User $user): array
    {
        set_time_limit(120);

        $client = new Client(
            apiKey: config('services.anthropic.key'),
            baseUrl: config('services.anthropic.base_url'),
        );
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $history);
        $tools = $this->toolDefinitions($user);

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $response = $client->beta->messages->create(
                model: config('services.anthropic.model'),
                maxTokens: 4000,
                system: $this->systemPrompt($user),
                messages: $messages,
                tools: $tools,
                outputConfig: ['effort' => 'low'],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );

            if ($response->stopReason === 'refusal') {
                throw new \RuntimeException('Claude declined the request.');
            }

            if ($response->stopReason !== 'tool_use') {
                $text = $this->textOf($response->content);
                if ($text === '') {
                    throw new \RuntimeException('Claude returned no text.');
                }

                return ['reply' => $text, 'recipes' => $this->cardsMentionedIn($text), 'mode' => 'ai'];
            }

            $results = [];
            foreach ($response->content as $block) {
                if ($block->type !== 'tool_use') {
                    continue;
                }

                try {
                    $output = $this->runTool($block->name, (array) $block->input, $user);
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => json_encode($output)];
                } catch (Throwable $e) {
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => $e->getMessage(), 'isError' => true];
                }
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new \RuntimeException('Claude used too many tool rounds.');
    }

    private function systemPrompt(?User $user): string
    {
        $prompt = <<<'PROMPT'
You are the kitchen assistant inside FridgeToFork, a web app that turns the ingredients people already have into recipes from world cuisines.

Help people decide what to cook, find recipes in the FridgeToFork library, understand a recipe, adapt it (servings, substitutions, timing), and use the app. Politely steer unrelated requests back to cooking.

Use the tools for anything about specific recipes: only recommend recipes that the tools return, and write each recipe title exactly as the tool gives it, so the app can show it as a card. General cooking advice (techniques, substitutions, food safety) can come from your own knowledge.

Keep replies short and friendly: two to five sentences, or a short list of up to four recipes with one line each. Write plain text without markdown headings or tables.

App pages you can point people to: My Fridge (/fridge) ranks recipes by what they already have; Recipes (/recipes) is the searchable library; Cuisine Map (/cuisines) browses by country; each recipe has a step-by-step Cook Mode with timers and voice prompts; signed-in cooks also get Meal Plan, a Shopping list built from the plan, Preferences for diet and allergies, and a Dashboard.
PROMPT;

        if ($user) {
            $diets = implode(', ', $user->dietary_preferences ?? []) ?: 'none set';
            $allergies = implode(', ', $user->allergies ?? []) ?: 'none set';
            $prompt .= "\n\nThe person is signed in as {$user->name}. Diet preferences: {$diets}. Allergies: {$allergies}. Skill level: " . ($user->skill_level ?: 'not set') . '. Respect the allergies in every suggestion. The get_my_fridge tool reads their saved fridge.';
        } else {
            $prompt .= "\n\nThe person is not signed in, so there is no saved fridge; ask what ingredients they have when it helps.";
        }

        return $prompt;
    }

    private function toolDefinitions(?User $user): array
    {
        $tools = [
            [
                'name' => 'find_recipes_by_ingredients',
                'description' => 'Rank library recipes by how many of the given ingredients they use. Returns each recipe with its match percentage and missing ingredients. Use this when the person says what they have.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'ingredients' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ingredient names, e.g. ["eggs", "rice", "onion"]'],
                        'max_missing' => ['type' => 'integer', 'description' => 'Maximum missing ingredients allowed per recipe (default 4)'],
                    ],
                    'required' => ['ingredients'],
                ],
            ],
            [
                'name' => 'search_recipes',
                'description' => 'Search the recipe library by name or keyword, cuisine country, diet tag, difficulty and maximum total time. All filters are optional.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Words to look for in the title or description'],
                        'cuisine' => ['type' => 'string', 'description' => 'Country or adjective, e.g. "Italy" or "Bengali"'],
                        'diet' => ['type' => 'string', 'enum' => self::DIETS],
                        'difficulty' => ['type' => 'string', 'enum' => ['beginner', 'intermediate', 'advanced']],
                        'max_minutes' => ['type' => 'integer', 'description' => 'Maximum prep plus cook time in minutes'],
                    ],
                ],
            ],
            [
                'name' => 'get_recipe',
                'description' => 'Get one recipe in full: ingredients with quantities, numbered steps, servings, time, diet tags and nutrition per serving.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'recipe_id' => ['type' => 'integer'],
                    ],
                    'required' => ['recipe_id'],
                ],
            ],
        ];

        if ($user) {
            $tools[] = [
                'name' => 'get_my_fridge',
                'description' => "List the ingredients saved in the signed-in person's fridge.",
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }

        return $tools;
    }

    private function runTool(string $name, array $input, ?User $user): array
    {
        return match ($name) {
            'find_recipes_by_ingredients' => $this->toolFindByIngredients($input, $user),
            'search_recipes' => $this->toolSearch($input),
            'get_recipe' => $this->toolGetRecipe($input),
            'get_my_fridge' => $this->toolMyFridge($user),
            default => throw new \InvalidArgumentException("Unknown tool {$name}."),
        };
    }

    private function toolFindByIngredients(array $input, ?User $user): array
    {
        $names = array_filter(array_map('strval', (array) ($input['ingredients'] ?? [])));
        if ($names === []) {
            throw new \InvalidArgumentException('Give at least one ingredient.');
        }

        $ids = $this->matcher->resolveIngredientIds($names);
        $filters = $this->matcher->filtersForUser($user, [
            'max_missing' => max(0, min(10, (int) ($input['max_missing'] ?? 4))),
            'limit' => 6,
        ]);

        return [
            'recognised_ingredients' => Ingredient::whereIn('id', $ids)->pluck('name')->all(),
            'matches' => $this->matcher->match($ids, $filters)->map(fn (array $row) => $this->summary($row['recipe']) + [
                'match_percent' => (int) round($row['match_ratio'] * 100),
                'missing' => collect($row['missing'])->pluck('name')->all(),
            ])->all(),
        ];
    }

    private function toolSearch(array $input): array
    {
        $recipes = $this->searchLibrary(
            query: $input['query'] ?? null,
            cuisineCode: CuisineCatalog::resolveCode($input['cuisine'] ?? null),
            diet: $input['diet'] ?? null,
            difficulty: $input['difficulty'] ?? null,
            maxMinutes: isset($input['max_minutes']) ? (int) $input['max_minutes'] : null,
            limit: 6,
        );

        return ['results' => $recipes->map(fn (Recipe $r) => $this->summary($r))->all()];
    }

    private function toolGetRecipe(array $input): array
    {
        $recipe = Recipe::find((int) ($input['recipe_id'] ?? 0));
        if (!$recipe) {
            throw new \InvalidArgumentException('No recipe with that id.');
        }

        return $this->summary($recipe) + [
            'description' => $recipe->description,
            'servings' => $recipe->servings,
            'ingredients' => $recipe->ingredients,
            'steps' => $recipe->instructions,
            'nutrition_per_serving' => $recipe->nutrition,
        ];
    }

    private function toolMyFridge(?User $user): array
    {
        return ['ingredients' => $user?->pantryItems()->with('ingredient:id,name')->get()
            ->pluck('ingredient.name')->filter()->values()->all() ?? []];
    }

    private function summary(Recipe $recipe): array
    {
        $this->seen[$recipe->id] = $recipe;

        return [
            'id' => $recipe->id,
            'title' => $recipe->title,
            'cuisine' => $recipe->cuisine_country,
            'difficulty' => $recipe->difficulty,
            'total_minutes' => $recipe->total_minutes,
            'diet_tags' => $recipe->diet_tags ?? [],
            'rating' => round((float) $recipe->average_rating, 1),
            'calories_per_serving' => $recipe->nutrition['calories'] ?? null,
        ];
    }

    private function textOf(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if ($block->type === 'text') {
                $parts[] = $block->text;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /** Cards for the recipes the reply actually names. */
    private function cardsMentionedIn(string $text): array
    {
        $lower = Str::lower($text);

        return collect($this->seen)
            ->filter(fn (Recipe $r) => Str::contains($lower, Str::lower($r->title)))
            ->sortBy(fn (Recipe $r) => strpos($lower, Str::lower($r->title)))
            ->take(self::MAX_CARDS)
            ->map(fn (Recipe $r) => $this->card($r))
            ->values()
            ->all();
    }

    private function card(Recipe $recipe, ?int $matchPercent = null): array
    {
        return [
            'id' => $recipe->id,
            'title' => $recipe->title,
            'cuisine' => $recipe->cuisine_country,
            'total_minutes' => $recipe->total_minutes,
            'difficulty' => $recipe->difficulty,
            'image_url' => $recipe->image_url,
            'match_percent' => $matchPercent,
        ];
    }

    // --------------------------------------------------------------- built-in

    private function replyBuiltIn(string $message, ?User $user): array
    {
        $text = Str::lower($message);

        if ($help = $this->navigationHelp($text)) {
            return ['reply' => $help, 'recipes' => [], 'mode' => 'assistant'];
        }

        $ingredientIds = $this->ingredientsMentioned($text);
        if ($ingredientIds->isNotEmpty()) {
            $matches = $this->matcher->match($ingredientIds, $this->matcher->filtersForUser($user, ['max_missing' => 4, 'limit' => 3]));
            $names = Ingredient::whereIn('id', $ingredientIds)->pluck('name')->implode(', ');

            if ($matches->isEmpty()) {
                return ['reply' => "I couldn't find a recipe that works with {$names} without too many extra ingredients. Try adding a few more things you have, or open My Fridge to experiment.", 'recipes' => [], 'mode' => 'assistant'];
            }

            $lines = $matches->map(function (array $row) {
                $missing = collect($row['missing'])->pluck('name');
                $need = $missing->isEmpty() ? 'you have everything' : 'you still need ' . $missing->implode(', ');

                return '- ' . $row['recipe']->title . ' (' . (int) round($row['match_ratio'] * 100) . "% match, {$need})";
            });

            return [
                'reply' => "With {$names}, these are your best options:\n" . $lines->implode("\n"),
                'recipes' => $matches->map(fn (array $row) => $this->card($row['recipe'], (int) round($row['match_ratio'] * 100)))->all(),
                'mode' => 'assistant',
            ];
        }

        $cuisine = $this->cuisineMentioned($text);
        $diet = collect(self::DIETS)->first(fn ($d) => Str::contains($text, [$d, str_replace('-', ' ', $d)]));
        $maxMinutes = $this->minutesMentioned($text);

        if ($cuisine || $diet || $maxMinutes) {
            $recipes = $this->searchLibrary(null, $cuisine, $diet, null, $maxMinutes, 3);
            $what = collect([
                $diet,
                $cuisine ? CuisineCatalog::nameFor($cuisine) : null,
                $maxMinutes ? "ready within {$maxMinutes} minutes" : null,
            ])->filter()->implode(', ');

            if ($recipes->isEmpty()) {
                return ['reply' => "I don't have a recipe that is {$what} yet. Try the Recipes page filters or the Cuisine Map.", 'recipes' => [], 'mode' => 'assistant'];
            }

            $lines = $recipes->map(fn (Recipe $r) => "- {$r->title} ({$r->cuisine_country}, {$r->total_minutes} min, {$r->difficulty})");

            return [
                'reply' => "Here are some recipes ({$what}):\n" . $lines->implode("\n"),
                'recipes' => $recipes->map(fn (Recipe $r) => $this->card($r))->values()->all(),
                'mode' => 'assistant',
            ];
        }

        return [
            'reply' => "I can help you decide what to cook. Tell me what's in your fridge (for example \"I have eggs, rice and onion\"), or ask for a cuisine, a diet or a time limit, like \"quick vegetarian dinner\" or \"something Italian\".",
            'recipes' => [],
            'mode' => 'assistant',
        ];
    }

    private function navigationHelp(string $text): ?string
    {
        $topics = [
            ['shopping', 'Open Shopping (sign in first) and choose "Rebuild from meal plan". It adds up the ingredients for your planned week, groups them by aisle and lets you tick items off.'],
            ['meal plan', 'Open Meal Plan (sign in first), pick a day and a meal slot, and choose a recipe. You will see the calories for each day.'],
            ['cook mode', 'Open any recipe and press "Start cooking". Cook Mode shows one step at a time, with timers and optional voice prompts, and you can change the servings.'],
            ['sign up', 'Press "Sign Up" at the top right. An account keeps your fridge, favourites and meal plan between visits.'],
            ['favourite', 'Open a recipe and press "Add Favorite". Your favourites appear on your Dashboard.'],
            ['favorite', 'Open a recipe and press "Add Favorite". Your favourites appear on your Dashboard.'],
            ['review', 'Open a recipe you did not write, choose a star rating and add a comment. Reviews give the author points on the leaderboard.'],
            ['add a recipe', 'Sign in and press "Add recipe" at the top. If you do not upload a photo, FridgeToFork draws an illustration for you.'],
            ['map', 'The Cuisine Map shows every country with recipes. Tap a pin to see that country\'s dishes.'],
            ['allerg', 'Set your diet and allergies on the Preferences page. My Fridge then hides recipes that do not fit.'],
        ];

        foreach ($topics as [$keyword, $answer]) {
            if (Str::contains($text, $keyword) && Str::contains($text, ['how', 'where', 'what is', 'can i', 'help'])) {
                return $answer;
            }
        }

        return null;
    }

    /** @return Collection<int, int> */
    private function ingredientsMentioned(string $text): Collection
    {
        $words = ' ' . preg_replace('/[^a-z\s-]/', ' ', $text) . ' ';

        return Ingredient::query()->get(['id', 'name', 'aliases'])
            ->filter(function (Ingredient $ingredient) use ($words) {
                $names = collect([$ingredient->name])->merge($ingredient->aliases ?? [])
                    ->map(fn ($n) => Str::lower((string) $n))
                    ->filter(fn ($n) => strlen($n) >= 3);

                return $names->contains(fn ($n) => preg_match('/\s' . preg_quote($n, '/') . '(e?s)?\s/', $words) === 1);
            })
            ->pluck('id')
            ->values();
    }

    private function cuisineMentioned(string $text): ?string
    {
        $words = preg_split('/[^a-z-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $candidates = $words;
        for ($i = 0; $i < count($words) - 1; $i++) {
            $candidates[] = $words[$i] . ' ' . $words[$i + 1];
        }

        foreach ($candidates as $candidate) {
            if (strlen($candidate) > 2 && ($code = CuisineCatalog::resolveCode($candidate))) {
                return $code;
            }
        }

        return null;
    }

    private function minutesMentioned(string $text): ?int
    {
        if (preg_match('/(\d{1,3})\s*(min|minute)/', $text, $m)) {
            return (int) $m[1];
        }

        return Str::contains($text, ['quick', 'fast', 'hurry', 'short on time']) ? 30 : null;
    }

    /** @return Collection<int, Recipe> */
    private function searchLibrary(?string $query, ?string $cuisineCode, ?string $diet, ?string $difficulty, ?int $maxMinutes, int $limit): Collection
    {
        $builder = Recipe::query()->orderByDesc('average_rating');

        if ($query) {
            $builder->where(fn ($q) => $q->where('title', 'like', "%{$query}%")->orWhere('description', 'like', "%{$query}%"));
        }
        if ($cuisineCode) {
            $builder->where('cuisine_code', $cuisineCode);
        }
        if ($difficulty) {
            $builder->where('difficulty', $difficulty);
        }
        if ($maxMinutes) {
            $builder->whereRaw('(COALESCE(prep_minutes, 0) + COALESCE(cook_minutes, 0)) <= ?', [$maxMinutes]);
        }

        return $builder->get()
            ->filter(fn (Recipe $r) => !$diet || in_array($diet, $r->diet_tags ?? [], true))
            ->take($limit)
            ->values();
    }
}
