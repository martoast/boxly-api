<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderItemRequest;
use App\Http\Requests\UpdateOrderItemRequest;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OrderItemController extends Controller
{
    /**
     * Store a new item in the order
     */
    public function store(StoreOrderItemRequest $request, Order $order)
    {
        DB::beginTransaction();
        
        try {
            // Create the order item
            $item = $order->items()->create([
                'product_url' => $request->product_url,
                'product_name' => $request->product_name,
                'quantity' => $request->quantity,
                'declared_value' => $request->declared_value,
                'tracking_number' => $request->tracking_number,
                'tracking_url' => $request->tracking_url,
                'carrier' => $request->carrier,
            ]);

            // Auto-detect retailer if URL is provided
            if ($item->product_url && !$item->retailer) {
                $item->retailer = $item->extractRetailer();
            }
            
            // Auto-detect carrier if tracking number is provided
            if (!$item->carrier && $item->tracking_number) {
                $item->carrier = $item->detectCarrier();
            }

            // Handle proof of purchase file upload
            if ($request->hasFile('proof_of_purchase')) {
                $file = $request->file('proof_of_purchase');
                
                // Create storage path
                $user = $request->user();
                $userName = Str::slug($user->name);
                $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}";
                
                // Generate filename
                $extension = $file->getClientOriginalExtension();
                $filename = "proof-of-purchase-" . time() . ".{$extension}";
                
                // Store the file
                $path = Storage::disk('spaces')->putFileAs(
                    $storagePath,
                    $file,
                    $filename,
                    'public'
                );
                
                // Build the public URL
                $url = config('filesystems.disks.spaces.url') . '/' . $path;
                
                // Update the item with file information
                $item->update([
                    'proof_of_purchase_path' => $path,
                    'proof_of_purchase_filename' => $file->getClientOriginalName(),
                    'proof_of_purchase_mime_type' => $file->getClientMimeType(),
                    'proof_of_purchase_size' => $file->getSize(),
                    'proof_of_purchase_url' => $url,
                ]);
            }
            
            $item->save();

            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Item added to order',
                'data' => $item->fresh()
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Clean up uploaded file if it exists
            if (isset($path)) {
                Storage::disk('spaces')->delete($path);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to add item to order',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update an item in the order
     */
    public function update(UpdateOrderItemRequest $request, Order $order, OrderItem $item)
    {
        $item->update($request->validated());

        // Re-detect carrier if tracking number changed
        if ($request->has('tracking_number') && !$request->has('carrier')) {
            $item->carrier = $item->detectCarrier();
            $item->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Item updated successfully',
            'data' => $item->fresh()
        ]);
    }

    /**
     * UPDATED: Remove an item from the order
     * Strict checks: only before processing and not for arrived items
     */
    public function destroy(Request $request, Order $order, OrderItem $item)
    {
        // Check authorization
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // STRICT: Only allow deletion before processing starts
        if (!in_array($order->status, [
            Order::STATUS_COLLECTING,
            Order::STATUS_AWAITING_PACKAGES,
            Order::STATUS_PACKAGES_COMPLETE
        ])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot remove items - order is already being processed or has been completed. Please contact support for assistance.'
            ], 403);
        }

        // Verify item belongs to this order
        if ($item->order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'Item does not belong to this order'
            ], 404);
        }

        // STRICT: Prevent deletion of items that have already arrived
        if ($item->arrived) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete items that have already arrived at the warehouse. Please contact support if you need to make changes to arrived items.'
            ], 400);
        }

        // Delete the item (this will also delete the file via model event)
        $item->delete();

        return response()->json([
            'success' => true,
            'message' => 'Item removed from order'
        ]);
    }

    /**
     * View/Download proof of purchase file
     */
    public function viewProof(Request $request, Order $order, OrderItem $item)
    {
        // Check authorization - allow if user owns the order OR user is admin
        $user = $request->user();
        $isOwner = $order->user_id === $user->id;
        $isAdmin = $user->isAdmin();
        
        if (!$isOwner && !$isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Verify item belongs to order
        if ($item->order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'Item does not belong to this order'
            ], 404);
        }

        // Check if proof of purchase exists
        if (!$item->proof_of_purchase_path) {
            return response()->json([
                'success' => false,
                'message' => 'No proof of purchase file found'
            ], 404);
        }

        try {
            // For public files, just redirect to the URL
            if ($item->proof_of_purchase_url) {
                return redirect($item->proof_of_purchase_url);
            }
            
            // Fallback: Stream the file from storage
            if (!Storage::disk('spaces')->exists($item->proof_of_purchase_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            return response()->streamDownload(
                function () use ($item) {
                    echo Storage::disk('spaces')->get($item->proof_of_purchase_path);
                },
                $item->proof_of_purchase_filename,
                [
                    'Content-Type' => $item->proof_of_purchase_mime_type,
                ]
            );
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error accessing file',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}