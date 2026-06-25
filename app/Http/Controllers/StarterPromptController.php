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
            ->get(['id', 'title', 'prompt_text', 'image_url', 'image_query', 'emoji']);

        return response()->json(['success' => true, 'data' => $prompts]);
    }
}
