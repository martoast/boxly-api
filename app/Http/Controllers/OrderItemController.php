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
            $user = $request->user();
            $userName = Str::slug($user->name);

            // Create the order item
            $item = $order->items()->create([
                'product_url' => $request->product_url,
                'product_name' => $request->product_name,
                'quantity' => $request->quantity,
                'declared_value' => $request->declared_value,
                'tracking_number' => $request->tracking_number,
                'tracking_url' => $request->tracking_url,
                'carrier' => $request->carrier,
                'estimated_delivery_date' => $request->estimated_delivery_date,
                // product_image_url will be updated if file is uploaded, 
                // or it might come from the scraper if we implemented one (logic below)
            ]);

            // Auto-detect retailer
            if ($item->product_url && !$item->retailer) {
                $item->retailer = $item->extractRetailer();
            }
            
            // Auto-detect carrier
            if (!$item->carrier && $item->tracking_number) {
                $item->carrier = $item->detectCarrier();
            }

            // 1. Handle Proof of Purchase Upload
            if ($request->hasFile('proof_of_purchase')) {
                $file = $request->file('proof_of_purchase');
                $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}/proof";
                
                $extension = $file->getClientOriginalExtension();
                $filename = "proof-" . time() . ".{$extension}";
                
                $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                $url = config('filesystems.disks.spaces.url') . '/' . $path;
                
                $item->update([
                    'proof_of_purchase_path' => $path,
                    'proof_of_purchase_filename' => $file->getClientOriginalName(),
                    'proof_of_purchase_mime_type' => $file->getClientMimeType(),
                    'proof_of_purchase_size' => $file->getSize(),
                    'proof_of_purchase_url' => $url,
                ]);
            }

            // 2. Handle Product Image Upload
            if ($request->hasFile('product_image')) {
                $imgFile = $request->file('product_image');
                $imgStoragePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}/image";
                
                $imgExt = $imgFile->getClientOriginalExtension();
                $imgFilename = "product-" . time() . ".{$imgExt}";
                
                $imgPath = Storage::disk('spaces')->putFileAs($imgStoragePath, $imgFile, $imgFilename, 'public');
                $imgUrl = config('filesystems.disks.spaces.url') . '/' . $imgPath;

                $item->update([
                    'product_image_path' => $imgPath,
                    'product_image_filename' => $imgFile->getClientOriginalName(),
                    'product_image_mime_type' => $imgFile->getClientMimeType(),
                    'product_image_size' => $imgFile->getSize(),
                    'product_image_url' => $imgUrl, // Overwrite any URL with the uploaded file
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
            // Clean up uploaded files if needed
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
        $user = $request->user();
        $userName = Str::slug($user->name);

        // Update basic fields
        $item->update($request->safe()->except(['proof_of_purchase', 'product_image']));

        // Re-detect carrier if tracking number changed
        if ($request->has('tracking_number') && !$request->has('carrier')) {
            $item->carrier = $item->detectCarrier();
            $item->save();
        }

        // 1. Handle Proof of Purchase Replacement
        if ($request->hasFile('proof_of_purchase')) {
            // Delete old file
            $item->deleteProofOfPurchase();

            $file = $request->file('proof_of_purchase');
            $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}/proof";
            $filename = "proof-" . time() . "." . $file->getClientOriginalExtension();
            
            $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
            $url = config('filesystems.disks.spaces.url') . '/' . $path;
            
            $item->update([
                'proof_of_purchase_path' => $path,
                'proof_of_purchase_filename' => $file->getClientOriginalName(),
                'proof_of_purchase_mime_type' => $file->getClientMimeType(),
                'proof_of_purchase_size' => $file->getSize(),
                'proof_of_purchase_url' => $url,
            ]);
        }

        // 2. Handle Product Image Replacement
        if ($request->hasFile('product_image')) {
            // Delete old file
            $item->deleteProductImage();

            $imgFile = $request->file('product_image');
            $imgStoragePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}/items/{$item->id}/image";
            $imgFilename = "product-" . time() . "." . $imgFile->getClientOriginalExtension();
            
            $imgPath = Storage::disk('spaces')->putFileAs($imgStoragePath, $imgFile, $imgFilename, 'public');
            $imgUrl = config('filesystems.disks.spaces.url') . '/' . $imgPath;

            $item->update([
                'product_image_path' => $imgPath,
                'product_image_filename' => $imgFile->getClientOriginalName(),
                'product_image_mime_type' => $imgFile->getClientMimeType(),
                'product_image_size' => $imgFile->getSize(),
                'product_image_url' => $imgUrl,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Item updated successfully',
            'data' => $item->fresh()
        ]);
    }

    /**
     * Remove an item from the order
     */
    public function destroy(Request $request, Order $order, OrderItem $item)
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if (!in_array($order->status, [Order::STATUS_COLLECTING, Order::STATUS_AWAITING_PACKAGES, Order::STATUS_PACKAGES_COMPLETE])) {
            return response()->json(['success' => false, 'message' => 'Cannot remove items from processed orders'], 403);
        }

        if ($item->order_id !== $order->id) {
            return response()->json(['success' => false, 'message' => 'Item mismatch'], 404);
        }

        if ($item->arrived) {
            return response()->json(['success' => false, 'message' => 'Cannot delete arrived items'], 400);
        }

        // Model events handle file deletion (proof & product image)
        $item->delete();

        return response()->json(['success' => true, 'message' => 'Item removed']);
    }

    /**
     * View/Download proof of purchase file
     */
    public function viewProof(Request $request, Order $order, OrderItem $item)
    {
        $user = $request->user();
        if ($order->user_id !== $user->id && !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if (!$item->proof_of_purchase_path) {
            return response()->json(['success' => false, 'message' => 'File not found'], 404);
        }

        // Redirect to public URL
        if ($item->proof_of_purchase_url) {
            return redirect($item->proof_of_purchase_url);
        }

        return response()->json(['success' => false, 'message' => 'URL missing'], 404);
    }
}