<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StarterPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdminStarterPromptController extends Controller
{
    public function index(Request $request)
    {
        $query = StarterPrompt::query();

        if ($search = $request->input('search')) {
            $query->where('title', 'like', "%{$search}%");
        }
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $prompts = $query->orderBy('sort_order')->orderBy('id')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json(['success' => true, 'data' => $prompts]);
    }

    public function show(StarterPrompt $starterPrompt)
    {
        return response()->json(['success' => true, 'data' => $starterPrompt]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'prompt_text' => 'required|string',
            'image_url'   => 'nullable|url|max:500',
            'image_query' => 'nullable|string|max:255',
            'emoji'       => 'nullable|string|max:16',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $prompt = StarterPrompt::create($validated);
        return response()->json(['success' => true, 'data' => $prompt], 201);
    }

    public function update(Request $request, StarterPrompt $starterPrompt)
    {
        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'prompt_text' => 'sometimes|string',
            'image_url'   => 'nullable|url|max:500',
            'image_query' => 'nullable|string|max:255',
            'emoji'       => 'nullable|string|max:16',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $starterPrompt->update($validated);
        return response()->json(['success' => true, 'data' => $starterPrompt->fresh()]);
    }

    public function destroy(StarterPrompt $starterPrompt)
    {
        $starterPrompt->delete();
        return response()->json(['success' => true, 'message' => 'Starter prompt deleted']);
    }

    /**
     * Upload a card image to Spaces and set image_url.
     */
    public function uploadImage(Request $request, StarterPrompt $starterPrompt)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        try {
            $file = $request->file('image');
            $path = "starter-prompts/{$starterPrompt->id}/image-" . time() . '.' . $file->getClientOriginalExtension();
            Storage::disk('spaces')->putFileAs(dirname($path), $file, basename($path), [
                'visibility' => 'public',
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);
            $url = config('filesystems.disks.spaces.url') . '/' . $path;

            $starterPrompt->update(['image_url' => $url]);

            return response()->json(['success' => true, 'data' => $starterPrompt->fresh()]);
        } catch (\Exception $e) {
            Log::error('Starter prompt image upload failed', ['id' => $starterPrompt->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
