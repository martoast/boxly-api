<?php

namespace App\Http\Controllers;

use App\Models\StarterPrompt;

class StarterPromptController extends Controller
{
    /**
     * Public list of active starter prompt cards for the shopping assistant.
     */
    public function index()
    {
        $prompts = StarterPrompt::active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'title', 'prompt_text', 'image_url', 'image_query', 'resolved_image_url', 'emoji'])
            ->map(function (StarterPrompt $prompt) {
                $prompt->image = $prompt->image_url ?? $prompt->resolved_image_url;

                return $prompt;
            });

        return response()->json(['success' => true, 'data' => $prompts]);
    }
}
