<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\DropOffReceiptCreated;
use App\Models\DropOffReceipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Drop-off receipts — admin/warehouse records a customer handing packages over,
 * then emails them the confirmation. Mounted under both /admin and /employee.
 */
class AdminDropOffReceiptController extends Controller
{
    public function index(Request $request)
    {
        $query = DropOffReceipt::with(['user:id,name,email', 'creator:id,name']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($userId = $request->input('user_id')) {
            $query->where('user_id', $userId);
        }

        $receipts = $query->orderByDesc('dropped_off_at')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json(['success' => true, 'data' => $receipts]);
    }

    public function show(DropOffReceipt $dropOffReceipt)
    {
        return response()->json([
            'success' => true,
            'data'    => $dropOffReceipt->load(['user:id,name,email,phone', 'creator:id,name']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'        => 'required|exists:users,id',
            'description'    => 'required|string',
            'dropped_off_at' => 'nullable|date',
        ]);

        $receipt = DropOffReceipt::create([
            'receipt_number' => DropOffReceipt::generateReceiptNumber(),
            'user_id'        => $validated['user_id'],
            'created_by'     => $request->user()->id,
            'description'    => $validated['description'],
            'dropped_off_at' => $validated['dropped_off_at'] ?? now()->toDateString(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => $receipt->load(['user:id,name,email', 'creator:id,name']),
        ], 201);
    }

    public function update(Request $request, DropOffReceipt $dropOffReceipt)
    {
        $validated = $request->validate([
            'user_id'        => 'sometimes|exists:users,id',
            'description'    => 'sometimes|string',
            'dropped_off_at' => 'sometimes|date',
        ]);

        $dropOffReceipt->update($validated);

        return response()->json([
            'success' => true,
            'data'    => $dropOffReceipt->fresh()->load(['user:id,name,email', 'creator:id,name']),
        ]);
    }

    public function destroy(DropOffReceipt $dropOffReceipt)
    {
        $dropOffReceipt->delete();

        return response()->json(['success' => true, 'message' => 'Drop-off receipt deleted']);
    }

    /**
     * Append photos of what was dropped off. Same Spaces flow as
     * EmployeeOrderController::uploadArrivalImages.
     */
    public function uploadImages(Request $request, DropOffReceipt $dropOffReceipt)
    {
        $request->validate([
            'images'   => 'required|array|min:1',
            'images.*' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        try {
            $images = $dropOffReceipt->images ?? [];

            foreach ($request->file('images') as $i => $file) {
                $filename = 'photo-' . (count($images) + $i + 1) . '-' . time() . '.' . $file->getClientOriginalExtension();
                $path = Storage::disk('spaces')->putFileAs(
                    "drop-offs/{$dropOffReceipt->receipt_number}",
                    $file,
                    $filename,
                    'public'
                );

                $images[] = [
                    'path'      => $path,
                    'url'       => config('filesystems.disks.spaces.url') . '/' . $path,
                    'filename'  => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size'      => $file->getSize(),
                ];
            }

            $dropOffReceipt->update(['images' => $images]);

            return response()->json([
                'success' => true,
                'data'    => $dropOffReceipt->fresh()->load(['user:id,name,email', 'creator:id,name']),
            ]);
        } catch (\Exception $e) {
            Log::error('Drop-off receipt image upload failed', [
                'receipt_id' => $dropOffReceipt->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove one photo by its storage path.
     */
    public function deleteImage(Request $request, DropOffReceipt $dropOffReceipt)
    {
        $validated = $request->validate(['path' => 'required|string']);

        $images = collect($dropOffReceipt->images ?? [])
            ->reject(fn ($image) => ($image['path'] ?? null) === $validated['path'])
            ->values()
            ->all();

        $dropOffReceipt->update(['images' => $images]);

        try {
            Storage::disk('spaces')->delete($validated['path']);
        } catch (\Exception $e) {
            // The row is the source of truth; a stray object in Spaces is harmless.
            Log::warning('Drop-off receipt image delete failed on Spaces', [
                'receipt_id' => $dropOffReceipt->id,
                'path'       => $validated['path'],
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $dropOffReceipt->fresh()->load(['user:id,name,email', 'creator:id,name']),
        ]);
    }

    /**
     * Email the receipt to the customer. Never automatic — the admin sends it
     * once the photos are attached, and may resend.
     */
    public function sendEmail(DropOffReceipt $dropOffReceipt)
    {
        $user = $dropOffReceipt->user;

        if (! $user || ! $user->email) {
            return response()->json([
                'success' => false,
                'message' => 'Customer has no email address.',
            ], 422);
        }

        try {
            Mail::to($user)->queue(new DropOffReceiptCreated($dropOffReceipt));

            $dropOffReceipt->update(['email_sent_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => "Receipt emailed to {$user->email}.",
                'data'    => $dropOffReceipt->fresh()->load(['user:id,name,email', 'creator:id,name']),
            ]);
        } catch (\Exception $e) {
            Log::error('Drop-off receipt email failed', [
                'receipt_id' => $dropOffReceipt->id,
                'error'      => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
