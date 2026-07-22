<?php

namespace App\Http\Controllers;

use App\Models\OrderStatusEvent;
use Illuminate\Http\Request;

class OrderStatusEventController extends Controller
{
    /**
     * Cursor read of the order-status outbox: events with id > `after`, in
     * id order. Consumers (Jarvis's CRM sync) keep the last id they saw and
     * poll — cheap indexed query, no events ever missed while they're away.
     */
    public function index(Request $request)
    {
        $v = $request->validate([
            'after' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $events = OrderStatusEvent::query()
            ->where('id', '>', $v['after'] ?? 0)
            ->orderBy('id')
            ->limit($v['limit'] ?? 200)
            ->get(['id', 'order_id', 'user_id', 'from_status', 'to_status', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => $events,
            'cursor' => $events->last()?->id ?? ($v['after'] ?? 0),
        ]);
    }
}
