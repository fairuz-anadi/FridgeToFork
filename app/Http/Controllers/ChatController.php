<?php

namespace App\Http\Controllers;

use App\Http\Services\ChatAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __invoke(Request $request, ChatAssistantService $assistant): JsonResponse
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1|max:20',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required|string|max:1000',
        ]);

        // Drop anything before the first user turn (e.g. the widget's greeting)
        // and require the conversation to end with the user's new message.
        $history = collect($validated['messages'])
            ->skipUntil(fn (array $m) => $m['role'] === 'user')
            ->values()
            ->all();

        if ($history === [] || end($history)['role'] !== 'user') {
            return response()->json(['message' => 'The last message must come from the user.'], 422);
        }

        return response()->json($assistant->reply($history, $request->user('sanctum')));
    }
}
