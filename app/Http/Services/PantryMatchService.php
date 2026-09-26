<?php

namespace App\Http\Services;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ingredient-Based Search: rank recipes by how much of them the cook can
 * already make from what is in the fridge.
 */
class PantryMatchService
{
    /**
     * @param  array<int, string>  $names  Free-text ingredient names.
     * @return Collection<int, int>  Ingredient ids.
     */
    public function resolveIngredientIds(array $names): Collection
    {
        return collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->map(fn ($name) => Ingredient::slugify($name))
            ->unique()
            ->pipe(fn (Collection $slugs) => Ingredient::whereIn('slug', $slugs)->pluck('id'));
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $ingredientIds
     * @param  array{
     *     max_missing?: int, diets?: array<int, string>, skill?: string|null,
     *     cuisine?: string|null, region?: string|null, max_minutes?: int|null,
     *     allergies?: array<int, string>, limit?: int,
     *     expiring_ids?: array<int, int>
     * }  $filters
     */
    public function match($ingredientIds, array $filters = []): Collection
    {
        $ingredientIds = collect($ingredientIds)->map(fn ($id) => (int) $id)->unique();
        $expiringIds = collect($filters['expiring_ids'] ?? [])->map(fn ($id) => (int) $id);
        $maxMissing = (int) ($filters['max_missing'] ?? 3);
        $limit = (int) ($filters['limit'] ?? 30);

        $query = Recipe::with(['user:id,name,username', 'categories:id,name', 'ingredientRecords:id,name,slug,aisle'])
            ->withCount('reviews');

        if (!empty($filters['cuisine'])) {
            $query->where('cuisine_code', $filters['cuisine']);
        }

        if (!empty($filters['region'])) {
            $query->where('cuisine_region', $filters['region']);
        }

        if (!empty($filters['skill'])) {
            $query->whereIn('difficulty', $this->skillLadder($filters['skill']));
        }

        if (!empty($filters['max_minutes'])) {
            $minutes = (int) $filters['max_minutes'];
            $query->whereRaw('(COALESCE(prep_minutes, 0) + COALESCE(cook_minutes, 0)) <= ?', [$minutes]);
        }

        $recipes = $query->get();

        return $recipes
            ->map(fn (Recipe $recipe) => $this->score($recipe, $ingredientIds, $expiringIds))
            ->reject(function (array $row) use ($maxMissing, $filters) {
                if ($row['required_count'] === 0) {
                    return true;
                }

                if (count($row['missing']) > $maxMissing) {
                    return true;
                }

                return $this->violatesDiet($row['recipe'], $filters)
                    || $this->hitsAllergy($row['recipe'], $filters);
            })
            ->sortBy([
                // "Use it up first": each soon-to-expire ingredient a recipe
                // uses is worth an extra 10 points of match.
                fn (array $a, array $b) => $this->rank($b) <=> $this->rank($a),
                fn (array $a, array $b) => count($a['missing']) <=> count($b['missing']),
                fn (array $a, array $b) => $b['recipe']->average_rating <=> $a['recipe']->average_rating,
            ])
            ->take($limit)
            ->values();
    }

    /**
     * Everything a cook of the given skill level can reasonably attempt —
     * an advanced cook still sees beginner recipes.
     *
     * @return array<int, string>
     */
    public function skillLadder(string $skill): array
    {
        return match ($skill) {
            'beginner' => ['beginner'],
            'intermediate' => ['beginner', 'intermediate'],
            'advanced' => ['beginner', 'intermediate', 'advanced'],
            default => ['beginner', 'intermediate', 'advanced'],
        };
    }

    /** Pull the filter defaults off the signed-in cook's profile. */
    public function filtersForUser(?User $user, array $overrides = []): array
    {
        $base = [
            'diets' => $user?->dietary_preferences ?? [],
            'allergies' => $user?->allergies ?? [],
            'skill' => $user?->skill_level,
        ];

        return array_merge($base, array_filter($overrides, fn ($value) => $value !== null && $value !== ''));
    }

    private function rank(array $row): float
    {
        return $row['match_ratio'] + 0.1 * count($row['uses_expiring']);
    }

    /**
     * @param  Collection<int, int>  $ingredientIds
     * @param  Collection<int, int>  $expiringIds
     */
    private function score(Recipe $recipe, Collection $ingredientIds, ?Collection $expiringIds = null): array
    {
        $required = $recipe->ingredientRecords->reject(fn ($i) => (bool) $i->pivot->is_optional);
        $requiredCount = $required->count();

        $have = $required->filter(fn ($i) => $ingredientIds->contains($i->id));
        $missing = $required->reject(fn ($i) => $ingredientIds->contains($i->id));

        return [
            'recipe' => $recipe,
            'required_count' => $requiredCount,
            'have_count' => $have->count(),
            'match_ratio' => $requiredCount > 0 ? round($have->count() / $requiredCount, 3) : 0.0,
            'uses_expiring' => $have
                ->filter(fn ($i) => $expiringIds?->contains($i->id))
                ->pluck('name')
                ->values()
                ->all(),
            'missing' => $missing
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'aisle' => $i->aisle, 'raw_text' => $i->pivot->raw_text])
                ->values()
                ->all(),
        ];
    }

    private function violatesDiet(Recipe $recipe, array $filters): bool
    {
        $diets = collect($filters['diets'] ?? [])->filter()->map(fn ($d) => strtolower((string) $d));

        if ($diets->isEmpty()) {
            return false;
        }

        $tags = collect($recipe->diet_tags ?? [])->map(fn ($t) => strtolower((string) $t));

        // Every preference the cook set must be satisfied by the recipe.
        return $diets->contains(fn ($diet) => !$tags->contains($diet));
    }

    private function hitsAllergy(Recipe $recipe, array $filters): bool
    {
        $allergies = collect($filters['allergies'] ?? [])
            ->filter()
            ->map(fn ($a) => Ingredient::slugify((string) $a));

        if ($allergies->isEmpty()) {
            return false;
        }

        return $recipe->ingredientRecords
            ->contains(fn ($ingredient) => $allergies->contains($ingredient->slug));
    }
}
