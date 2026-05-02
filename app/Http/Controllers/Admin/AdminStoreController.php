<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminStoreController extends Controller
{
    public function index(Request $request)
    {
        $query = Store::query()->withCount('products');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $stores = $query->orderBy('sort_order')->orderBy('name')->paginate((int) $request->input('per_page', 50));

        return response()->json(['success' => true, 'data' => $stores]);
    }

    public function show(Store $store)
    {
        return response()->json(['success' => true, 'data' => $store->loadCount('products')]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'nullable|string|max:255|unique:stores,slug',
            'base_url'    => 'nullable|url|max:500',
            'logo_url'    => 'nullable|url|max:500',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $store = Store::create($validated);
        return response()->json(['success' => true, 'data' => $store], 201);
    }

    public function update(Request $request, Store $store)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'slug'        => 'sometimes|string|max:255|unique:stores,slug,' . $store->id,
            'base_url'    => 'nullable|url|max:500',
            'logo_url'    => 'nullable|url|max:500',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $store->update($validated);
        return response()->json(['success' => true, 'data' => $store->fresh()]);
    }

    public function destroy(Store $store)
    {
        $store->delete();
        return response()->json(['success' => true, 'message' => 'Store deleted']);
    }

    /**
     * Upload a logo image to Spaces and set logo_url.
     */
    public function uploadLogo(Request $request, Store $store)
    {
        $request->validate([
            'logo' => 'required|image|mimes:jpeg,jpg,png,webp,svg|max:5120',
        ]);

        try {
            $file = $request->file('logo');
            $path = "stores/{$store->slug}/logo-" . time() . '.' . $file->getClientOriginalExtension();
            Storage::disk('spaces')->putFileAs(dirname($path), $file, basename($path), 'public');
            $url = config('filesystems.disks.spaces.url') . '/' . $path;

            $store->update(['logo_url' => $url]);

            return response()->json(['success' => true, 'data' => $store->fresh()]);
        } catch (\Exception $e) {
            Log::error('Store logo upload failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
