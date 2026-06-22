<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Admin CRUD for the AI assistant's knowledge wiki. Every write busts the
 * assembled-wiki cache so the assistant picks up edits within seconds.
 */
class AdminKnowledgeController extends Controller
{
    public function index(Request $request)
    {
        $query = KnowledgeArticle::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('section', 'like', "%{$search}%");
            });
        }

        // Full list (the wiki is small); ordered for the grouped sidebar.
        $articles = $query->orderBy('section')->orderBy('sort_order')->orderBy('title')->get();

        return response()->json(['success' => true, 'data' => $articles]);
    }

    public function show(KnowledgeArticle $knowledge)
    {
        return response()->json(['success' => true, 'data' => $knowledge]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'        => 'required|string|max:255',
            'slug'         => 'nullable|string|max:255|unique:knowledge_articles,slug',
            'section'      => 'nullable|string|max:120',
            'content'      => 'required|string',
            'sort_order'   => 'nullable|integer|min:0',
            'is_published' => 'nullable|boolean',
        ]);

        $validated['updated_by'] = $request->user()->id;

        $article = KnowledgeArticle::create($validated);
        $this->bustCache();

        return response()->json(['success' => true, 'data' => $article], 201);
    }

    public function update(Request $request, KnowledgeArticle $knowledge)
    {
        $validated = $request->validate([
            'title'        => 'sometimes|string|max:255',
            'slug'         => 'sometimes|string|max:255|unique:knowledge_articles,slug,' . $knowledge->id,
            'section'      => 'nullable|string|max:120',
            'content'      => 'sometimes|string',
            'sort_order'   => 'nullable|integer|min:0',
            'is_published' => 'nullable|boolean',
        ]);

        $validated['updated_by'] = $request->user()->id;

        $knowledge->update($validated);
        $this->bustCache();

        return response()->json(['success' => true, 'data' => $knowledge->fresh()]);
    }

    public function destroy(KnowledgeArticle $knowledge)
    {
        $knowledge->delete();
        $this->bustCache();

        return response()->json(['success' => true, 'message' => 'Article deleted']);
    }

    private function bustCache(): void
    {
        Cache::forget(KnowledgeArticle::CACHE_KEY);
    }
}
