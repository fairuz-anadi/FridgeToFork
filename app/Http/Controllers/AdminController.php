<?php

namespace App\Http\Controllers;

use App\Mail\ContactReplyMail;
use App\Models\ContactSubmission;
use App\Models\Recipe;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    private const UPLOAD_REWARD = 10;

    public function dashboard()
    {
        $totalRecipes = Recipe::count();
        $totalReviews = Review::count();
        $totalUsers = User::count();
        $totalContacts = ContactSubmission::where('status', 'open')->count();
        $memberCount = User::where('is_admin', false)->count();

        $recentRecipes = Recipe::with(['user:id,name,username', 'categories:id,name'])
            ->withCount('reviews')
            ->latest()
            ->limit(8)
            ->get();

        $recentReviews = Review::with(['user:id,name,username', 'recipe:id,title,user_id'])
            ->latest()
            ->limit(8)
            ->get();

        $recentContacts = ContactSubmission::latest()->limit(8)->get();
        $userDirectory = User::withCount(['recipes', 'reviews', 'favorites'])
            ->latest()
            ->limit(12)
            ->get(['id', 'name', 'username', 'email', 'points', 'is_admin', 'created_at']);

        $topCategory = \App\Models\Category::query()
            ->whereHas('recipes')
            ->withCount('recipes')
            ->orderByDesc('recipes_count')
            ->orderBy('name')
            ->first(['id', 'name']);

        $mostReviewedRecipe = Recipe::with(['user:id,name'])
            ->withCount('reviews')
            ->orderByDesc('reviews_count')
            ->orderByDesc('average_rating')
            ->first(['id', 'title', 'user_id', 'average_rating']);

        $newestMember = User::latest()->first(['id', 'name', 'username', 'created_at', 'is_admin']);

        return response()->json([
            'stats' => [
                'users' => $totalUsers,
                'members' => $memberCount,
                'admins' => $totalUsers - $memberCount,
                'recipes' => $totalRecipes,
                'reviews' => $totalReviews,
                'contacts' => $totalContacts,
                'average_recipe_rating' => round((float) (Recipe::avg('average_rating') ?? 0), 2),
                'reviews_per_recipe' => round($totalRecipes > 0 ? $totalReviews / $totalRecipes : 0, 2),
                'recipes_this_week' => Recipe::where('created_at', '>=', now()->subDays(7))->count(),
                'users_this_week' => User::where('created_at', '>=', now()->subDays(7))->count(),
            ],
            'highlights' => [
                'top_category' => $topCategory,
                'most_reviewed_recipe' => $mostReviewedRecipe,
                'newest_member' => $newestMember,
            ],
            'recent_contacts' => $recentContacts,
            'recent_recipes' => $recentRecipes,
            'recent_reviews' => $recentReviews,
            'users' => $userDirectory,
        ]);
    }

    public function deleteRecipe(Recipe $recipe)
    {
        $owner = $recipe->user;
        $deduction = min($owner->points, self::UPLOAD_REWARD);
        $owner->decrement('points', $deduction);

        if ($recipe->image_path) {
            Storage::disk('public')->delete($recipe->image_path);
        }

        $recipe->reviews()->delete();
        $recipe->categories()->detach();
        $recipe->favoritedByUsers()->detach();
        $recipe->delete();

        return response()->json([
            'message' => 'Recipe deleted successfully by admin.',
        ]);
    }

    public function deleteUser(Request $request, User $user)
    {
        if ((string) $request->user()->id === (string) $user->id) {
            return response()->json([
                'message' => 'Admin accounts cannot delete themselves.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user->is_admin) {
            return response()->json([
                'message' => 'Admin accounts are protected and cannot be deleted.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Tips the user received and reviews on others' recipes point at the
        // user without a cascade, so they are removed first, all or nothing.
        DB::transaction(function () use ($user) {
            $user->receivedTips()->delete();
            $user->sentTips()->delete();
            $user->reviews()->delete();
            $user->favorites()->detach();
            $user->recipes->each(function (Recipe $recipe) {
                if ($recipe->image_path) {
                    Storage::disk('public')->delete($recipe->image_path);
                }
                $recipe->reviews()->delete();
                $recipe->categories()->detach();
                $recipe->favoritedByUsers()->detach();
                $recipe->delete();
            });
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json([
            'message' => 'User deleted successfully by admin.',
        ]);
    }

    public function deleteReview(Review $review)
    {
        $recipe = $review->recipe;
        $owner = $recipe?->user;

        if ($owner) {
            $owner->decrement('points', min($owner->points, $review->rating));
        }

        $review->delete();

        if ($recipe) {
            $recipe->update([
                'average_rating' => round((float) ($recipe->reviews()->avg('rating') ?? 0), 2),
            ]);
        }

        return response()->json([
            'message' => 'Review deleted successfully by admin.',
        ]);
    }

    /** The complaint inbox: open first, then resolved, archived last. */
    public function contacts(Request $request)
    {
        $status = $request->query('status');

        $contacts = ContactSubmission::with('user:id,name,username')
            ->when(in_array($status, ContactSubmission::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'resolved' THEN 1 ELSE 2 END")
            ->latest()
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $contacts,
            'meta' => [
                'open' => ContactSubmission::where('status', 'open')->count(),
                'resolved' => ContactSubmission::where('status', 'resolved')->count(),
                'archived' => ContactSubmission::where('status', 'archived')->count(),
            ],
        ]);
    }

    /** Reply to a complaint and/or change its status; a new reply is emailed to the sender. */
    public function updateContact(Request $request, ContactSubmission $contact)
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(ContactSubmission::STATUSES)],
            'admin_reply' => 'sometimes|nullable|string|max:5000',
        ]);

        $reply = trim((string) ($validated['admin_reply'] ?? ''));
        $newReply = $reply !== '' && $reply !== $contact->admin_reply;

        if (array_key_exists('admin_reply', $validated)) {
            $contact->admin_reply = $reply !== '' ? $reply : null;
        }
        if (isset($validated['status'])) {
            $contact->status = $validated['status'];
            if ($validated['status'] === 'open') {
                $contact->resolved_at = null;
            } elseif ($validated['status'] === 'resolved' || !$contact->resolved_at) {
                $contact->resolved_at = now();
            }
        }
        $contact->save();

        if ($newReply) {
            try {
                Mail::to($contact->email, $contact->name)->send(new ContactReplyMail($contact));
            } catch (\Throwable $e) {
                Log::warning('Complaint reply email could not be sent: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => match ($contact->status) {
                'resolved' => 'Complaint marked as resolved.',
                'archived' => 'Complaint archived. Find it under Archived.',
                default => 'Complaint updated.',
            },
            'data' => $contact->load('user:id,name,username'),
        ]);
    }

    public function deleteContact(ContactSubmission $contact)
    {
        $contact->delete();

        return response()->json([
            'message' => 'Message deleted permanently.',
        ]);
    }
}
