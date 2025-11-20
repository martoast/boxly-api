<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Mail\PurchaseRequestCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PurchaseRequestController extends Controller
{
    public function index(Request $request)
    {
        $requests = PurchaseRequest::with('items')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Create a new purchase request
     */
    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_name' => 'required|string|max:255',
            'items.*.product_url' => 'required|string|max:2000',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.options' => 'nullable', // Can be array or JSON string via FormData
            'items.*.notes' => 'nullable|string|max:500',
            'items.*.image' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:10240', // 10MB max
        ]);

        DB::beginTransaction();

        try {
            $user = $request->user();

            // 1. Create the Request Ticket
            $pr = PurchaseRequest::create([
                'user_id' => $user->id,
                'request_number' => PurchaseRequest::generateRequestNumber(),
                'status' => PurchaseRequest::STATUS_PENDING_REVIEW,
                'currency' => 'usd',
            ]);

            // 2. Process Items
            $itemsInput = $request->input('items');

            foreach ($itemsInput as $index => $itemData) {
                
                // Handle options: If sent via FormData, it might be a JSON string or an array
                $options = null;
                if (isset($itemData['options'])) {
                    $options = is_string($itemData['options']) 
                        ? json_decode($itemData['options'], true) 
                        : $itemData['options'];
                }

                // Create Item Record
                $item = PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id,
                    'product_name' => $itemData['product_name'],
                    'product_url' => $itemData['product_url'],
                    'price' => $itemData['price'],
                    'quantity' => $itemData['quantity'],
                    'options' => $options,
                    'notes' => $itemData['notes'] ?? null,
                ]);

                // 3. Handle Image Upload
                // Check if a file exists for this specific item index
                if ($request->hasFile("items.{$index}.image")) {
                    $file = $request->file("items.{$index}.image");
                    
                    // Create storage path
                    $userName = Str::slug($user->name);
                    $storagePath = "users/{$userName}-{$user->id}/requests/{$pr->request_number}/items/{$item->id}";
                    
                    $filename = "image-" . time() . "." . $file->getClientOriginalExtension();
                    
                    // Upload
                    $path = Storage::disk('spaces')->putFileAs(
                        $storagePath,
                        $file,
                        $filename,
                        'public'
                    );
                    
                    $url = config('filesystems.disks.spaces.url') . '/' . $path;
                    
                    // Update item with file info
                    $item->update([
                        'image_path' => $path,
                        'image_filename' => $file->getClientOriginalName(),
                        'image_mime_type' => $file->getClientMimeType(),
                        'image_size' => $file->getSize(),
                        'image_url' => $url,
                    ]);
                }
            }

            DB::commit();

            Log::info('Purchase Request created', ['id' => $pr->id, 'user_id' => $user->id]);

            // Send Notification
            try {
                Mail::to($user)->queue(new PurchaseRequestCreated($pr));
                Log::info('Purchase Request confirmation email queued for ' . $user->email);
            } catch (\Exception $e) {
                Log::error('Failed to queue purchase request email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Request submitted successfully.',
                'data' => $pr->load('items')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Purchase Request Create Failed: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create request',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest)
    {
        if ($purchaseRequest->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $purchaseRequest->load('items')
        ]);
    }
}