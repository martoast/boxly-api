<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Gender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdminGenderController extends Controller
{
    public function index(Request $request)
    {
        $query = Gender::query()->withCount('products');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $genders = $query->orderBy('sort_order')->orderBy('name')->paginate((int) $request->input('per_page', 50));

        return response()->json(['success' => true, 'data' => $genders]);
    }

    public function show(Gender $gender)
    {
        return response()->json(['success' => true, 'data' => $gender->loadCount('products')]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'slug'        => 'nullable|string|max:255|unique:genders,slug',
            'description' => 'nullable|string',
            'image_url'   => 'nullable|url|max:500',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $gender = Gender::create($validated);
        return response()->json(['success' => true, 'data' => $gender], 201);
    }

    public function update(Request $request, Gender $gender)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'slug'        => 'sometimes|string|max:255|unique:genders,slug,' . $gender->id,
            'description' => 'nullable|string',
            'image_url'   => 'nullable|url|max:500',
            'is_active'   => 'nullable|boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $gender->update($validated);
        return response()->json(['success' => true, 'data' => $gender->fresh()]);
    }

    public function destroy(Gender $gender)
    {
        $gender->delete(); // products keep gender_id null via nullOnDelete
        return response()->json(['success' => true, 'message' => 'Gender deleted']);
    }

    public function uploadImage(Request $request, Gender $gender)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        try {
            $file = $request->file('image');
            $path = "genders/{$gender->slug}/img-" . time() . '.' . $file->getClientOriginalExtension();
            Storage::disk('spaces')->putFileAs(dirname($path), $file, basename($path), 'public');
            $url = config('filesystems.disks.spaces.url') . '/' . $path;

            $gender->update(['image_url' => $url]);
            return response()->json(['success' => true, 'data' => $gender->fresh()]);
        } catch (\Exception $e) {
            Log::error('Gender image upload failed', ['gender_id' => $gender->id, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
