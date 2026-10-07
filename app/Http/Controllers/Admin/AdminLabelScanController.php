<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabelScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Label scans — packages that arrived at the warehouse, read off label photos.
 * The reading happens before this (barcodes on the phone, name via the app's
 * /api/label-read); store() only saves one photo and the package(s) read from it.
 * Mounted under both /admin and /employee.
 */
class AdminLabelScanController extends Controller
{
    public function index(Request $request)
    {
        $query = LabelScan::with('creator:id,name');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('tracking_number', 'like', "%{$search}%")
                  ->orWhere('recipient_name', 'like', "%{$search}%")
                  ->orWhere('ship_from', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('needs_check')) {
            $query->where('needs_check', true);
        }

        $scans = $query->orderByDesc('id')->paginate((int) $request->input('per_page', 100));

        return response()->json(['success' => true, 'data' => $scans]);
    }

    /**
     * One photo + the package(s) read from it. Usually one package; a photo that
     * shows two labels yields two rows sharing the image.
     */
    public function store(Request $request)
    {
        // Multipart upload: the packages ride along as one JSON string next to the photo.
        if (is_string($request->input('packages'))) {
            $request->merge(['packages' => json_decode($request->input('packages'), true)]);
        }

        $validated = $request->validate([
            'image'                            => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
            'batch'                            => 'nullable|string|max:40',
            'packages'                         => 'required|array|min:1|max:5',
            'packages.*.tracking_number'       => 'nullable|string|max:255',
            'packages.*.carrier'               => 'nullable|string|max:40',
            'packages.*.other_tracking'        => 'nullable|array',
            'packages.*.recipient_name'        => 'nullable|string|max:255',
            'packages.*.suite'                 => 'nullable|string|max:40',
            'packages.*.ship_from'             => 'nullable|string|max:255',
            'packages.*.store_order_numbers'   => 'nullable|array',
            'packages.*.barcodes'              => 'nullable|array',
            'packages.*.model_tracking_read'   => 'nullable|string|max:255',
            'packages.*.confidence'            => 'nullable|string|max:10',
            'packages.*.needs_check'           => 'nullable|boolean',
        ]);

        try {
            $file = $request->file('image');
            $path = Storage::disk('spaces')->putFileAs(
                'label-scans/' . now()->format('Y-m-d'),
                $file,
                Str::random(12) . '.' . $file->getClientOriginalExtension(),
                'public'
            );
            $url = config('filesystems.disks.spaces.url') . '/' . $path;
        } catch (\Exception $e) {
            Log::error('Label scan image upload failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Image upload failed'], 500);
        }

        $rows = [];
        foreach ($validated['packages'] as $package) {
            $rows[] = LabelScan::create([
                ...$package,
                'needs_check' => (bool) ($package['needs_check'] ?? false),
                'batch'       => $validated['batch'] ?? null,
                'image_path'  => $path,
                'image_url'   => $url,
                'created_by'  => $request->user()->id,
            ])->load('creator:id,name');
        }

        return response()->json(['success' => true, 'data' => $rows], 201);
    }

    /** Hand corrections: fix a name, fill in a hidden tracking number, clear the check flag. */
    public function update(Request $request, LabelScan $labelScan)
    {
        $validated = $request->validate([
            'tracking_number' => 'sometimes|nullable|string|max:255',
            'carrier'         => 'sometimes|nullable|string|max:40',
            'recipient_name'  => 'sometimes|nullable|string|max:255',
            'suite'           => 'sometimes|nullable|string|max:40',
            'ship_from'       => 'sometimes|nullable|string|max:255',
            'needs_check'     => 'sometimes|boolean',
        ]);

        $labelScan->update($validated);

        return response()->json(['success' => true, 'data' => $labelScan->fresh()->load('creator:id,name')]);
    }

    public function destroy(LabelScan $labelScan)
    {
        $labelScan->delete();

        return response()->json(['success' => true, 'message' => 'Label scan deleted']);
    }
}
