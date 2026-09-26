<?php

namespace App\Http\Controllers;

use App\Http\Services\PantryMatchService;
use Illuminate\Http\Request;

/**
 * Ingredient-Based Search — "what can I cook right now?".
 */
class PantrySearchController extends Controller
{
    public function __construct(private PantryMatchService $matcher)
    {
    }

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'ingredients' => 'sometimes|array',
            'ingredients.*' => 'required|string|max:120',
            'use_pantry' => 'sometimes|boolean',
            'max_missing' => 'sometimes|integer|min:0|max:50',
            'cuisine' => 'sometimes|nullable|string|max:2',
            'region' => 'sometimes|nullable|string|max:60',
            'skill' => 'sometimes|nullable|in:beginner,intermediate,advanced',
            'max_minutes' => 'sometimes|nullable|integer|min:5|max:600',
            'diets' => 'sometimes|array',
            'diets.*' => 'string|max:40',
            'limit' => 'sometimes|integer|min:1|max:60',
        ]);

        $user = $request->user('sanctum');
        $ingredientIds = collect();

        if ($request->boolean('use_pantry') && $user) {
            $ingredientIds = $user->pantryItems()->pluck('ingredient_id');
        }

        if (!empty($validated['ingredients'])) {
            $ingredientIds = $ingredientIds
                ->merge($this->matcher->resolveIngredientIds($validated['ingredients']))
                ->unique();
        }

        if ($ingredientIds->isEmpty()) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'ingredient_count' => 0,
                    'message' => 'Add at least one ingredient to see what you can cook.',
                ],
            ]);
        }

        $filters = $this->matcher->filtersForUser($user, [
            'max_missing' => $validated['max_missing'] ?? null,
            'cuisine' => $validated['cuisine'] ?? null,
            'region' => $validated['region'] ?? null,
            'skill' => $validated['skill'] ?? null,
            'max_minutes' => $validated['max_minutes'] ?? null,
            'diets' => $validated['diets'] ?? null,
            'limit' => $validated['limit'] ?? null,
        ]);

        // Signed-in cooks: rank recipes that use soon-to-expire fridge items first.
        $useSoon = $user
            ? $user->pantryItems()->useSoon()->with('ingredient:id,name')->get()
                ->filter(fn ($item) => $ingredientIds->contains($item->ingredient_id))
            : collect();
        $filters['expiring_ids'] = $useSoon->pluck('ingredient_id')->all();

        $favoriteIds = $user ? $user->favorites()->pluck('recipes.id') : collect();

        $matches = $this->matcher->match($ingredientIds, $filters)->map(function (array $row) use ($favoriteIds) {
            $recipe = $row['recipe'];
            $recipe->setAttribute('favorited_by_auth_user', $favoriteIds->contains($recipe->id));

            return [
                'recipe' => $recipe,
                'match_ratio' => $row['match_ratio'],
                'match_percent' => (int) round($row['match_ratio'] * 100),
                'have_count' => $row['have_count'],
                'required_count' => $row['required_count'],
                'missing' => $row['missing'],
                'uses_expiring' => $row['uses_expiring'],
            ];
        });

        return response()->json([
            'data' => $matches,
            'meta' => [
                'ingredient_count' => $ingredientIds->count(),
                'max_missing' => $filters['max_missing'] ?? 3,
                'cook_now' => $matches->where('match_ratio', 1.0)->count(),
                'use_soon' => $useSoon
                    ->map(fn ($item) => ['name' => $item->ingredient?->name, 'days_left' => $item->days_left])
                    ->sortBy('days_left')
                    ->values(),
            ],
        ]);
    }
}
