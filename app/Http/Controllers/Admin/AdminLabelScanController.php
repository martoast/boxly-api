<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabelScan;
use App\Models\User;
use App\Services\LabelScanMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Label scans — packages that arrived at the warehouse, read off label photos.
 * The reading happens before this (barcodes on the phone, name via the app's
 * /api/label-read); store() only saves one photo and the package(s) read from it.
 * Mounted under both /admin and /employee.
 *
 * Two warehouses (2026-10-09): every scan records its `location` — san_diego (packages arriving from US
 * carriers) or tijuana (the same packages arriving across the border). A warehouse employee sees, counts,
 * edits and scans ONLY their own warehouse; admins see both (optionally one: location=san_diego|tijuana).
 */
class AdminLabelScanController extends Controller
{
    /**
     * Packages that arrived at the warehouse, newest first — one row per package read off a label photo:
     * recipient_name, tracking_number (exact, from the barcode), carrier, other_tracking, image_url, needs_check
     * (true = a person should look: no barcode, no name, or an unsure read), created_at. Query: search (name /
     * tracking number), needs_check=1, since (ISO date-time: only rows uploaded after it — poll for new arrivals),
     * until (ISO date-time: only rows uploaded before it — with since, one day / week / month), operator
     * (user id of who scanned them), location (san_diego | tijuana — admins; an employee always gets their
     * own warehouse), per_page (default 100), page. Paginated: data.data[], data.total,
     * data.last_page.
     */
    public function index(Request $request)
    {
        $query = $this->forWarehouse($request, LabelScan::with('creator:id,name'));

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
     * count}] (most first), data.window {since, until, tz}, data.operator, data.location. Query location
     * (san_diego | tijuana) for admins; a warehouse employee always counts their own warehouse.
     * Admin and warehouse employee.
     */
    public function stats(Request $request)
    {
        $validated = $request->validate([
            'day'      => 'nullable|date_format:Y-m-d|required_without_all:since,until',
            'since'    => 'nullable|date|required_without:day',
            'until'    => 'nullable|date|required_without:day',
            'operator' => 'nullable|integer',
            'tz'       => 'nullable|timezone',
            'location' => 'nullable|in:san_diego,tijuana',
        ]);
        $tz = $validated['tz'] ?? 'America/Los_Angeles';

        if (! empty($validated['day'])) {
            $since = \Illuminate\Support\Carbon::parse($validated['day'], $tz)->startOfDay()->utc();
            $until = $since->copy()->setTimezone($tz)->addDay()->startOfDay()->utc();
        } else {
            $since = \Illuminate\Support\Carbon::parse($validated['since'])->utc();
            $until = \Illuminate\Support\Carbon::parse($validated['until'])->utc();
        }

        $rows = $this->forWarehouse($request, LabelScan::query())
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
            'location'     => $this->warehouseOf($request),
        ]]);
    }

    /**
     * Save one label photo and the package(s) read from it (multipart: image file, packages = JSON array of
     * {tracking_number, carrier, recipient_name, needs_check, …}, optional batch). Normally called by the
     * label-scans page, which does the reading; a photo showing two labels yields two rows sharing the image.
     * A tracking number the warehouse already has is not saved twice: that row is returned with duplicate=true
     * (and when every package is one, nothing is uploaded: response duplicate=true, status 200).
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

        // Where it was scanned: an employee's own warehouse; an admin says which, default Tijuana — that is where the
        // admins scan (Alex, 2026-10-31: San Diego default mislabelled a whole Tijuana session).
        $location = $request->user()->isWarehouseEmployee()
            ? $request->user()->warehouseLocation()
            : (in_array($request->input('location'), User::LOCATIONS, true) ? $request->input('location') : User::LOCATION_TIJUANA);

        // A package this warehouse already has (the same box shot twice, two phones, a page reload) is not saved
        // again: its existing row comes back marked `duplicate`. Per warehouse — the San Diego and the Tijuana scan
        // of one box are both real (2026-11-17: 119 doubles in a month, mostly two shots of one label).
        $existing = [];
        foreach ($validated['packages'] as $i => $package) {
            $tracking = trim((string) ($package['tracking_number'] ?? ''));
            if ($tracking !== '' && ($row = LabelScan::with('creator:id,name')->where('location', $location)->where('tracking_number', $tracking)->orderBy('id')->first())) {
                $existing[$i] = $row->setAttribute('duplicate', true);
            }
        }
        if (count($existing) === count($validated['packages'])) {
            return response()->json(['success' => true, 'data' => array_values($existing), 'duplicate' => true]);
        }

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
        $matcher = new LabelScanMatcher(); // the customer each label names (confident matches only)
        foreach ($validated['packages'] as $i => $package) {
            if (isset($existing[$i])) {
                $rows[] = $existing[$i];
                continue;
            }
            $match = $matcher->match($package['recipient_name'] ?? null);
            $rows[] = LabelScan::create([
                ...$package,
                'user_id'      => $match['user_id'],
                'match_status' => $match['status'],
                'needs_check' => (bool) ($package['needs_check'] ?? false),
                'batch'       => $validated['batch'] ?? null,
                'image_path'  => $path,
                'image_url'   => $url,
                'created_by'  => $request->user()->id,
                'location'    => $location,
            ])->load('creator:id,name');
        }

        return response()->json(['success' => true, 'data' => $rows], 201);
    }

    /**
     * Correct one package: recipient_name, tracking_number, carrier, needs_check (false = checked by a person).
     * Admins may also move it to the other warehouse: location (san_diego | tijuana), and with whole_batch=1
     * every row of the same scanning session moves too, so a session saved under the wrong warehouse is fixed
     * in one go. Response `moved` = how many rows changed warehouse.
     */
    public function update(Request $request, LabelScan $labelScan)
    {
        $this->ensureSameWarehouse($request, $labelScan);
        $validated = $request->validate([
            'tracking_number' => 'sometimes|nullable|string|max:255',
            'carrier'         => 'sometimes|nullable|string|max:40',
            'recipient_name'  => 'sometimes|nullable|string|max:255',
            'suite'           => 'sometimes|nullable|string|max:40',
            'ship_from'       => 'sometimes|nullable|string|max:255',
            'needs_check'     => 'sometimes|boolean',
            'location'        => 'sometimes|in:' . implode(',', User::LOCATIONS),
            'whole_batch'     => 'sometimes|boolean',
            'user_id'         => 'sometimes|nullable|integer|exists:users,id',
        ]);

        // Only an admin moves scans between warehouses: an employee's scans are always their own warehouse.
        $location = $validated['location'] ?? null;
        $wholeBatch = (bool) ($validated['whole_batch'] ?? false);
        unset($validated['location'], $validated['whole_batch']);
        if ($location && $request->user()->isWarehouseEmployee()) {
            abort(403, 'Only an admin can move a scan to another warehouse.');
        }
        $moved = 0;
        if ($location) {
            $rows = $wholeBatch && $labelScan->batch
                ? LabelScan::where('batch', $labelScan->batch)
                : LabelScan::whereKey($labelScan->id);
            $moved = $rows->where('location', '!=', $location)->update(['location' => $location]);
        }

        // A person correcting the name or tracking has checked the label: clear "Revisar" unless they set it.
        if (! array_key_exists('needs_check', $validated)
            && (array_key_exists('recipient_name', $validated) || array_key_exists('tracking_number', $validated))) {
            $validated['needs_check'] = false;
        }

        // THE CUSTOMER: a person's choice (user_id, or null = "nobody") is kept as 'manual' and never re-matched; a corrected
        // name is matched again unless a person already chose.
        if (array_key_exists('user_id', $validated)) {
            $validated['match_status'] = 'manual';
        } elseif (array_key_exists('recipient_name', $validated) && $labelScan->match_status !== 'manual') {
            $match = (new LabelScanMatcher())->match($validated['recipient_name']);
            $validated['user_id'] = $match['user_id'];
            $validated['match_status'] = $match['status'];
        }
        // A HELD ROW CLEARED BY A PERSON (needs_check true -> false) records when: /label-scans/arrivals returns it in that window too
        if ($labelScan->needs_check && array_key_exists('needs_check', $validated) && $validated['needs_check'] === false) {
            $validated['cleared_at'] = now();
        }
        if ($validated) {
            $labelScan->update($validated);
        }

        return response()->json(['success' => true, 'data' => $labelScan->fresh()->load('creator:id,name'), 'moved' => $moved]);
    }

    /** Delete one package row (its photo stays in storage: another row from the same photo may use it). */
    public function destroy(Request $request, LabelScan $labelScan)
    {
        $this->ensureSameWarehouse($request, $labelScan);
        $labelScan->delete();

        return response()->json(['success' => true, 'message' => 'Label scan deleted']);
    }

    /**
     * THE DAY'S ARRIVALS BY CUSTOMER — for the 5 PM WhatsApp (Suite, 2026-11-25). Query: since (ISO date-time, required: rows
     * uploaded after it), until (ISO, default now: up to and including it), location (san_diego | tijuana, default san_diego).
     * Returns data.customers [{user_id, name, phone (E.164, only numbers that look like WhatsApp numbers), scans [...]}],
     * data.unmatched [scan + recipient_name + match_status (ambiguous | none | staff | manual | no_phone)], data.needs_check
     * [scan + recipient_name + user_id: held until a person clears the flag], data.window. A scan: {id (stable), tracking_number,
     * carrier, image_url (public, permanent), image_shared (the photo also shows another package's label: send no photo),
     * scanned_at (upload time, ISO), cleared_at (when a person cleared its needs_check, or null)}. A held row a person cleared
     * comes back in the window its cleared_at falls in too. Read by a token with the 'label-scans:arrivals' ability, or an admin.
     */
    public function arrivals(Request $request)
    {
        $validated = $request->validate([
            'since'    => 'required|date',
            'until'    => 'nullable|date',
            'location' => 'nullable|in:san_diego,tijuana',
        ]);
        $since = \Illuminate\Support\Carbon::parse($validated['since'])->utc();
        $until = isset($validated['until']) ? \Illuminate\Support\Carbon::parse($validated['until'])->utc() : now()->utc();
        $location = $validated['location'] ?? User::LOCATION_SAN_DIEGO;
        $rows = LabelScan::with('user:id,name,phone,role')
            ->where('location', $location)
            // uploaded in the window, or held and cleared by a person in it (same row, same id: callers dedupe by id)
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('created_at', '>', $since)->where('created_at', '<=', $until))
                ->orWhere(fn ($w) => $w->where('cleared_at', '>', $since)->where('cleared_at', '<=', $until)))
            ->orderBy('id')
            ->get();
        // a photo that holds several labels shows other people's names: counted over every row that uses it
        $photoUses = $rows->isEmpty() ? collect() : LabelScan::whereIn('image_url', $rows->pluck('image_url')->filter()->unique()->values())
            ->selectRaw('image_url, COUNT(*) as uses')->groupBy('image_url')->pluck('uses', 'image_url');
        $scan = fn (LabelScan $r) => [
            'id'              => $r->id,
            'tracking_number' => $r->tracking_number,
            'carrier'         => $r->carrier,
            'image_url'       => $r->image_url,
            'image_shared'    => (int) ($photoUses[$r->image_url] ?? 1) > 1,
            'scanned_at'      => $r->created_at?->toIso8601String(),
            'cleared_at'      => $r->cleared_at?->toIso8601String(),
        ];
        $customers = [];
        $unmatched = [];
        $held = [];
        foreach ($rows as $r) {
            if ($r->needs_check) {
                $held[] = $scan($r) + ['recipient_name' => $r->recipient_name, 'user_id' => $r->user_id];
                continue;
            }
            $u = $r->user;
            if (! $u || $u->role !== User::ROLE_CUSTOMER) {
                $unmatched[] = $scan($r) + ['recipient_name' => $r->recipient_name, 'match_status' => $r->match_status ?? 'none'];
                continue;
            }
            if (! preg_match('/^\+[1-9]\d{10,14}$/', (string) $u->phone)) {
                $unmatched[] = $scan($r) + ['recipient_name' => $r->recipient_name, 'match_status' => 'no_phone', 'user_id' => $u->id];
                continue;
            }
            $customers[$u->id] ??= ['user_id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'scans' => []];
            $customers[$u->id]['scans'][] = $scan($r);
        }
        return response()->json(['success' => true, 'data' => [
            'customers'   => array_values($customers),
            'unmatched'   => $unmatched,
            'needs_check' => $held,
            'window'      => ['since' => $since->toIso8601String(), 'until' => $until->toIso8601String(), 'location' => $location],
        ]]);
    }

    /**
     * Match existing scans to customers again (admin): every row uploaded after `since` (default: all) whose customer was not
     * chosen by a person. Optional location. Returns how many rows got each status.
     */
    public function rematch(Request $request)
    {
        $validated = $request->validate(['since' => 'nullable|date', 'location' => 'nullable|in:san_diego,tijuana']);
        $matcher = new LabelScanMatcher();
        $counts = [];
        LabelScan::query()
            ->where(fn ($q) => $q->whereNull('match_status')->orWhere('match_status', '!=', 'manual'))
            ->when($validated['since'] ?? null, fn ($q, $s) => $q->where('created_at', '>', \Illuminate\Support\Carbon::parse($s)->utc()))
            ->when($validated['location'] ?? null, fn ($q, $l) => $q->where('location', $l))
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use ($matcher, &$counts) {
                foreach ($chunk as $row) {
                    $m = $matcher->match($row->recipient_name);
                    $row->forceFill(['user_id' => $m['user_id'], 'match_status' => $m['status']])->saveQuietly();
                    $counts[$m['status']] = ($counts[$m['status']] ?? 0) + 1;
                }
            });
        return response()->json(['success' => true, 'data' => $counts]);
    }

    /** The warehouse a request is about: the employee's own, or the admin's location filter (null = both). */
    private function warehouseOf(Request $request): ?string
    {
        $user = $request->user();
        if ($user->isWarehouseEmployee()) {
            return $user->warehouseLocation();
        }
        $loc = $request->input('location');

        return in_array($loc, User::LOCATIONS, true) ? $loc : null;
    }

    private function forWarehouse(Request $request, $query)
    {
        $loc = $this->warehouseOf($request);

        return $loc ? $query->where('location', $loc) : $query;
    }

    /** An employee never edits or deletes the other warehouse's scans (404, as if it weren't there). */
    private function ensureSameWarehouse(Request $request, LabelScan $labelScan): void
    {
        $user = $request->user();
        if ($user->isWarehouseEmployee() && $labelScan->location !== $user->warehouseLocation()) {
            abort(404);
        }
    }
}
