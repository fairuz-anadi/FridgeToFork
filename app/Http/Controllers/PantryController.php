<?php

namespace App\Http\Controllers;

use App\Models\Ingredient;
use App\Models\PantryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "What's in my fridge" — the cook's own ingredient shelf.
 */
class PantryController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->itemsFor($request),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'quantity' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:30',
            'expires_on' => 'nullable|date',
        ]);

        $ingredient = Ingredient::resolve($validated['name']);

        $item = PantryItem::firstOrNew([
            'user_id' => $request->user()->id,
            'ingredient_id' => $ingredient->id,
        ]);

        // Only overwrite what was sent; a new item without a date gets the
        // typical shelf life for its aisle.
        $item->fill(collect($validated)->only(['quantity', 'unit', 'expires_on'])->all());
        if (!$item->exists && !array_key_exists('expires_on', $validated)) {
            $item->expires_on = PantryItem::estimatedExpiry($ingredient);
        }
        $item->save();

        return response()->json([
            'message' => $ingredient->name . ' added to your fridge.',
            'data' => $this->itemsFor($request),
        ], Response::HTTP_CREATED);
    }

    /** Replace the whole shelf in one call — used by the "quick add" chips. */
    public function sync(Request $request)
    {
        $validated = $request->validate([
            'names' => 'present|array',
            'names.*' => 'required|string|max:120',
        ]);

        $user = $request->user();
        $ids = collect($validated['names'])
            ->map(fn ($name) => Ingredient::resolve($name)->id)
            ->unique();

        $user->pantryItems()->whereNotIn('ingredient_id', $ids)->delete();

        foreach ($ids as $ingredientId) {
            PantryItem::firstOrCreate(
                ['user_id' => $user->id, 'ingredient_id' => $ingredientId],
                ['expires_on' => PantryItem::estimatedExpiry(Ingredient::find($ingredientId))]
            );
        }

        return response()->json([
            'message' => 'Fridge updated.',
            'data' => $this->itemsFor($request),
        ]);
    }

    /** Record how much is left and when it expires. */
    public function update(Request $request, PantryItem $pantryItem)
    {
        if ((string) $pantryItem->user_id !== (string) $request->user()->id) {
            return response()->json([
                'message' => 'You can only edit your own fridge.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'quantity' => 'sometimes|nullable|numeric|min:0',
            'unit' => 'sometimes|nullable|string|max:30',
            'expires_on' => 'sometimes|nullable|date',
        ]);

        $pantryItem->fill($validated)->save();

        return response()->json([
            'message' => 'Fridge item updated.',
            'data' => $this->itemsFor($request),
        ]);
    }

    public function destroy(Request $request, PantryItem $pantryItem)
    {
        if ((string) $pantryItem->user_id !== (string) $request->user()->id) {
            return response()->json([
                'message' => 'You can only edit your own fridge.',
            ], Response::HTTP_FORBIDDEN);
        }

        $pantryItem->delete();

        return response()->json([
            'message' => 'Item removed from your fridge.',
            'data' => $this->itemsFor($request),
        ]);
    }

    private function itemsFor(Request $request)
    {
        return $request->user()
            ->pantryItems()
            ->with('ingredient:id,name,slug,aisle')
            ->get()
            ->sortBy(fn (PantryItem $item) => $item->ingredient?->name)
            ->values();
    }
}
