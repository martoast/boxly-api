<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderArrivalImage;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmployeeOrderController extends Controller
{
    /**
     * List orders awaiting packages (Mau's main queue).
     * Searchable by customer name.
     */
    public function index(Request $request)
    {
        $query = Order::with(['user:id,name,phone', 'items'])
            ->where('status', Order::STATUS_AWAITING_PACKAGES)
            ->orderBy('created_at', 'asc');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $orders = $query->paginate(20);

        // Append arrived/pending counts to each order
        $orders->getCollection()->transform(function ($order) {
            $order->total_items = $order->items->count();
            $order->arrived_items = $order->items->where('arrived', true)->count();
            $order->pending_items = $order->total_items - $order->arrived_items;
            $order->all_items_arrived = $order->pending_items === 0 && $order->total_items > 0;
            return $order;
        });

        return response()->json([
            'success' => true,
            'data' => $orders,
        ]);
    }

    /**
     * Get a single order with all items.
     */
    public function show(Order $order)
    {
        $order->load(['user:id,name,phone', 'items', 'arrivalImages']);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Mark a single item as arrived (one-way — only admin can un-mark).
     * Accepts optional weight.
     */
    public function markItemArrived(Request $request, Order $order, OrderItem $item)
    {
        if ($item->order_id !== $order->id) {
            return response()->json([
                'success' => false,
                'message' => 'Item does not belong to this order.',
            ], 404);
        }

        if ($order->status !== Order::STATUS_AWAITING_PACKAGES) {
            return response()->json([
                'success' => false,
                'message' => 'This order is not awaiting packages.',
            ], 422);
        }

        if ($item->arrived) {
            return response()->json([
                'success' => false,
                'message' => 'Item is already marked as arrived. Contact an admin to undo this.',
            ], 422);
        }

        $request->validate([
            'weight' => 'nullable|numeric|min:0',
        ]);

        if ($request->filled('weight')) {
            $item->update(['weight' => $request->input('weight')]);
        }

        $item->markAsArrived();

        $order->update([
            'total_weight' => $order->calculateTotalWeight(),
        ]);

        $order->load('items');
        $order->all_items_arrived = $order->allItemsArrived();

        return response()->json([
            'success' => true,
            'message' => 'Item marked as arrived.',
            'data' => [
                'item' => $item->fresh(),
                'order' => $order,
            ],
        ]);
    }

    /**
     * Upload arrival images (label photos + contents photo).
     * Triggers packages_complete status and customer email.
     */
    public function uploadArrivalImages(Request $request, Order $order)
    {
        $request->validate([
            'labels'    => 'required|array|min:1',
            'labels.*'  => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
            'contents'  => 'required|array|min:1',
            'contents.*'=> 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        try {
            $user = $order->user;
            $userName = Str::slug($user->name);
            $storagePath = "users/{$userName}-{$user->id}/orders/{$order->order_number}";
            $contentsUrl = null;

            // Store label photos
            foreach ($request->file('labels') as $i => $file) {
                $filename = "label-" . ($i + 1) . "-" . time() . ".{$file->getClientOriginalExtension()}";
                $path = Storage::disk('spaces')->putFileAs($storagePath, $file, $filename, 'public');
                $url  = config('filesystems.disks.spaces.url') . '/' . $path;

                OrderArrivalImage::create([
                    'order_id'  => $order->id,
                    'type'      => 'label',
                    'path'      => $path,
                    'url'       => $url,
                    'filename'  => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size'      => $file->getSize(),
                ]);
            }

            // Store contents photos
            $contentsUrl = null;
            foreach ($request->file('contents') as $j => $contentsFile) {
                $filename    = "contents-" . ($j + 1) . "-" . time() . ".{$contentsFile->getClientOriginalExtension()}";
                $path        = Storage::disk('spaces')->putFileAs($storagePath, $contentsFile, $filename, 'public');
                $contentsUrl = config('filesystems.disks.spaces.url') . '/' . $path;

                OrderArrivalImage::create([
                    'order_id'  => $order->id,
                    'type'      => 'contents',
                    'path'      => $path,
                    'url'       => $contentsUrl,
                    'filename'  => $contentsFile->getClientOriginalName(),
                    'mime_type' => $contentsFile->getClientMimeType(),
                    'size'      => $contentsFile->getSize(),
                ]);
            }

            // Set arrival_image_url to last contents photo so admin panel can see it
            $lastContents = $request->file('contents');
            $lastContents = end($lastContents);
            $order->update([
                'arrival_image_path'      => $path,
                'arrival_image_filename'  => $lastContents->getClientOriginalName(),
                'arrival_image_mime_type' => $lastContents->getClientMimeType(),
                'arrival_image_size'      => $lastContents->getSize(),
                'arrival_image_url'       => $contentsUrl,
            ]);

            Log::info('Employee uploaded arrival images', [
                'order_id'       => $order->id,
                'employee_id'    => $request->user()->id,
                'label_count'    => count($request->file('labels')),
                'contents_count' => count($request->file('contents')),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Photos uploaded successfully.',
                'data'    => $order->fresh()->load(['user', 'items', 'arrivalImages']),
            ]);

        } catch (\Exception $e) {
            Log::error('Employee failed to upload arrival images', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
