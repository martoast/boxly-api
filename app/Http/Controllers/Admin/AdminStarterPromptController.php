<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StarterPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
            'title' => 'required|string|max:255',
            'prompt_text' => 'required|string',
            'image_url' => 'nullable|url|max:500',
            'image_query' => 'nullable|string|max:255',
            'emoji' => 'nullable|string|max:16',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $prompt = StarterPrompt::create($validated);
        $this->maybeResolveImage($prompt, queryChanged: true);

        return response()->json(['success' => true, 'data' => $prompt->fresh()], 201);
    }

    public function update(Request $request, StarterPrompt $starterPrompt)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'prompt_text' => 'sometimes|string',
            'image_url' => 'nullable|url|max:500',
            'image_query' => 'nullable|string|max:255',
            'emoji' => 'nullable|string|max:16',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $queryChanged = array_key_exists('image_query', $validated)
            && $validated['image_query'] !== $starterPrompt->image_query;

        $starterPrompt->update($validated);
        $this->maybeResolveImage($starterPrompt, $queryChanged);

        return response()->json(['success' => true, 'data' => $starterPrompt->fresh()]);
    }

    /**
     * Resolve a representative product photo for the card ONCE, at save time,
     * and copy it to Spaces — the search page used to resolve this live on
     * every page load (StarterPromptController::index / /api/card-image),
     * which is why the starter cards took seconds to paint. A failed
     * resolution just leaves the emoji fallback; it never fails the save.
     */
    private function maybeResolveImage(StarterPrompt $prompt, bool $queryChanged): void
    {
        $query = trim((string) $prompt->image_query);
        if ($query === '') {
            return;
        }
        if (! $queryChanged && ! empty($prompt->resolved_image_url)) {
            return;
        }

        try {
            $sourceUrl = $this->searchFirstProductImage($query);
            if (! $sourceUrl) {
                return;
            }

            $spacesUrl = $this->copyImageToSpaces($prompt->id, $sourceUrl);
            if ($spacesUrl) {
                $prompt->update(['resolved_image_url' => $spacesUrl]);
            }
        } catch (\Throwable $e) {
            Log::warning('Starter prompt image resolution failed', [
                'id' => $prompt->id,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Same product search /api/card-image runs today — take the first
     * result with an image.
     */
    private function searchFirstProductImage(string $query): ?string
    {
        $response = Http::timeout(8)->post(rtrim(config('app.url'), '/').'/products/search', [
            'query' => $query,
        ]);
        if (! $response->successful()) {
            return null;
        }

        foreach ($response->json('data.products') ?? [] as $product) {
            if (! empty($product['image'])) {
                return $product['image'];
            }
        }

        return null;
    }

    /**
     * Merchant CDNs may hotlink-block server-side downloads — a failure here
     * just bubbles up to maybeResolveImage's catch and leaves the fallback.
     */
    private function copyImageToSpaces(int $promptId, string $sourceUrl): ?string
    {
        $response = Http::timeout(8)->get($sourceUrl);
        if (! $response->successful() || $response->body() === '') {
            return null;
        }

        $ext = strtolower(pathinfo(parse_url($sourceUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{2,5}$/', $ext) ? $ext : 'jpg';
        $path = "starter-prompts/{$promptId}/resolved-".time().'.'.$ext;

        Storage::disk('spaces')->put($path, $response->body(), [
            'visibility' => 'public',
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        return config('filesystems.disks.spaces.url').'/'.$path;
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
            $path = "starter-prompts/{$starterPrompt->id}/image-".time().'.'.$file->getClientOriginalExtension();
            Storage::disk('spaces')->putFileAs(dirname($path), $file, basename($path), [
                'visibility' => 'public',
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);
            $url = config('filesystems.disks.spaces.url').'/'.$path;

            $starterPrompt->update(['image_url' => $url]);

            return response()->json(['success' => true, 'data' => $starterPrompt->fresh()]);
        } catch (\Exception $e) {
            Log::error('Starter prompt image upload failed', ['id' => $starterPrompt->id, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
