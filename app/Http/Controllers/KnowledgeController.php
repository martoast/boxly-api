<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\Cache;

/**
 * Public, read-only access to the assembled knowledge wiki — consumed by the AI
 * assistant (the Nuxt server has no DB, so it fetches the wiki over HTTP). Returns
 * only PUBLISHED articles. Cached 5 min; the admin controller busts it on edits.
 */
class KnowledgeController extends Controller
{
    public function index()
    {
        $payload = Cache::remember(KnowledgeArticle::CACHE_KEY, now()->addMinutes(5), function () {
            $articles = KnowledgeArticle::published()
                ->orderBy('section')->orderBy('sort_order')->orderBy('title')
                ->get(['title', 'slug', 'section', 'content', 'updated_at']);

            return [
                'markdown'   => $this->assemble($articles),
                'articles'   => $articles->map(fn ($a) => [
                    'title'   => $a->title,
                    'slug'    => $a->slug,
                    'section' => $a->section,
                ])->values(),
                'updated_at' => optional($articles->max('updated_at'))->toIso8601String(),
                'count'      => $articles->count(),
            ];
        });

        return response()->json(['success' => true, 'data' => $payload]);
    }

    /** Concatenate published articles into one LLM-friendly markdown document. */
    private function assemble($articles): string
    {
        $out = [];
        $currentSection = null;

        foreach ($articles as $a) {
            $section = $a->section ?: 'General';
            if ($section !== $currentSection) {
                $out[] = "# {$section}";
                $currentSection = $section;
            }
            $out[] = "## {$a->title}";
            $out[] = trim($a->content);
            $out[] = ''; // blank line between articles
        }

        return trim(implode("\n\n", $out));
    }
}
