<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

/**
 * Chat threads for the AI shopping assistant — history sidebar + resume.
 * All actions are scoped to the authenticated user.
 */
class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $conversations = $request->user()->conversations()
            ->get(['id', 'title', 'last_message_at', 'created_at']);

        return response()->json(['success' => true, 'data' => $conversations]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
        ]);

        $conversation = $request->user()->conversations()->create([
            'title' => $validated['title'] ?? null,
            'last_message_at' => now(),
        ]);

        return response()->json(['success' => true, 'data' => $conversation], 201);
    }

    public function show(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'messages' => $conversation->messages()->get(['id', 'role', 'content', 'created_at']),
            ],
        ]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);

        $validated = $request->validate(['title' => 'required|string|max:255']);
        $conversation->update(['title' => $validated['title']]);

        return response()->json(['success' => true, 'data' => $conversation]);
    }

    public function destroy(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);
        $conversation->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Append one or more messages to a thread (used by the chat backend per turn).
     * Auto-titles the thread from the first user message if still untitled.
     */
    public function addMessages(Request $request, Conversation $conversation)
    {
        $this->authorizeOwner($request, $conversation);

        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required',
        ]);

        foreach ($validated['messages'] as $m) {
            $conversation->messages()->create([
                'role' => $m['role'],
                'content' => $m['content'],
            ]);

            if (! $conversation->title && $m['role'] === 'user') {
                $conversation->title = $this->titleFrom($m['content']);
            }
        }

        $conversation->last_message_at = now();
        $conversation->save();

        return response()->json(['success' => true, 'data' => $conversation->fresh('messages')]);
    }

    /**
     * Claim a guest's in-progress conversation into the now-authenticated
     * account as a new thread (called right after chat-register).
     */
    public function claim(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|in:user,assistant',
            'messages.*.content' => 'required',
        ]);

        $conversation = $request->user()->conversations()->create([
            'title' => $validated['title'] ?? $this->titleFrom($validated['messages'][0]['content'] ?? 'New chat'),
            'last_message_at' => now(),
        ]);

        foreach ($validated['messages'] as $m) {
            $conversation->messages()->create(['role' => $m['role'], 'content' => $m['content']]);
        }

        return response()->json(['success' => true, 'data' => $conversation->load('messages')], 201);
    }

    private function authorizeOwner(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 403, 'Not your conversation.');
    }

    private function titleFrom($content): string
    {
        $text = is_string($content) ? $content : ($content['text'] ?? json_encode($content));
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));

        return mb_substr($text, 0, 60) ?: 'New chat';
    }
}
