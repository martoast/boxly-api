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
    /**
     * Packages that arrived at the warehouse, newest first — one row per package read off a label photo:
     * recipient_name, tracking_number (exact, from the barcode), carrier, other_tracking, image_url, needs_check
     * (true = a person should look: no barcode, no name, or an unsure read), created_at. Query: search (name /
     * tracking number), needs_check=1, since (ISO date-time: only rows uploaded after it — poll for new arrivals),
     * until (ISO date-time: only rows uploaded before it — with since, one day / week / month), operator
     * (user id of who scanned them), per_page (default 100), page. Paginated: data.data[], data.total,
     * data.last_page.
     */
    public function index(Request $request)
    {
        $query = LabelScan::with('creator:id,name');

        if ($since = $request->input('since')) {
            $query->where('created_at', '>', \Illuminate\Support\Carbon::parse($since));
        }

        if ($until = $request->input('until')) {
            $query->where('created_at', '<', \Illuminate\Support\Carbon::parse($until));
        }

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

        if ($operator = $request->input('operator')) {
            $query->where('created_by', (int) $operator);
        }

        $scans = $query->orderByDesc('id')->paginate((int) $request->input('per_page', 100));

        return response()->json(['success' => true, 'data' => $scans]);
    }

    /**
     * How many packages were scanned — in total, per warehouse day and per operator (who scanned them).
     * Answers "how many did Mauricio scan today?" and feeds the operator's day / week / month / year view.
     * Query: either day (YYYY-MM-DD, one warehouse day) or since + until (ISO date-times); operator (user id:
     * only that person's scans); tz (IANA zone the days are counted in; default America/Los_Angeles — the
     * San Diego warehouse, same clock as America/Tijuana). Returns data.total, data.needs_check,
     * data.per_day [{day, count}] (days with scans, oldest first), data.per_operator [{user_id, name,
     * count}] (most first), data.window {since, until, tz}, data.operator. Admin and warehouse employee.
     */
    public function stats(Request $request)
    {
        $validated = $request->validate([
            'day'      => 'nullable|date_format:Y-m-d|required_without_all:since,until',
            'since'    => 'nullable|date|required_without:day',
            'until'    => 'nullable|date|required_without:day',
            'operator' => 'nullable|integer',
            'tz'       => 'nullable|timezone',
        ]);
        $tz = $validated['tz'] ?? 'America/Los_Angeles';

        if (! empty($validated['day'])) {
            $since = \Illuminate\Support\Carbon::parse($validated['day'], $tz)->startOfDay()->utc();
            $until = $since->copy()->setTimezone($tz)->addDay()->startOfDay()->utc();
        } else {
            $since = \Illuminate\Support\Carbon::parse($validated['since'])->utc();
            $until = \Illuminate\Support\Carbon::parse($validated['until'])->utc();
        }

        $rows = LabelScan::query()
            ->with('creator:id,name')
            ->where('created_at', '>=', $since)
            ->where('created_at', '<', $until)
            ->when($validated['operator'] ?? null, fn ($q, $op) => $q->where('created_by', (int) $op))
            ->get(['created_at', 'needs_check', 'created_by']);

        $perDay = [];
        $perOperator = [];
        foreach ($rows as $row) {
            $day = $row->created_at->copy()->setTimezone($tz)->toDateString();
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
            $uid = (int) $row->created_by;
            $perOperator[$uid] ??= ['user_id' => $uid, 'name' => $row->creator?->name, 'count' => 0];
            $perOperator[$uid]['count']++;
        }
        ksort($perDay);
        usort($perOperator, fn ($a, $b) => $b['count'] <=> $a['count']);

        return response()->json(['success' => true, 'data' => [
            'total'        => $rows->count(),
            'needs_check'  => $rows->where('needs_check', true)->count(),
            'per_day'      => array_map(fn ($d, $c) => ['day' => $d, 'count' => $c], array_keys($perDay), $perDay),
            'per_operator' => array_values($perOperator),
            'window'       => ['since' => $since->toIso8601String(), 'until' => $until->toIso8601String(), 'tz' => $tz],
            'operator'     => isset($validated['operator']) ? (int) $validated['operator'] : null,
        ]]);
    }

    /**
     * Save one label photo and the package(s) read from it (multipart: image file, packages = JSON array of
     * {tracking_number, carrier, recipient_name, needs_check, …}, optional batch). Normally called by the
     * label-scans page, which does the reading; a photo showing two labels yields two rows sharing the image.
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

    /** Correct one package: recipient_name, tracking_number, carrier, needs_check (false = checked by a person). */
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

    /** Delete one package row (its photo stays in storage: another row from the same photo may use it). */
    public function destroy(LabelScan $labelScan)
    {
        $labelScan->delete();

        return response()->json(['success' => true, 'message' => 'Label scan deleted']);
    }
}
