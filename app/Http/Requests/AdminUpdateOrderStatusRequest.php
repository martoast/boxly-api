<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in(array_keys(Order::getStatuses()))
            ],
            'estimated_delivery_date' => [
                'required_if:status,' . Order::STATUS_SHIPPED,
                'nullable',
                'date',
                // Removed 'after:today' to allow admins to set any date
            ],
            'notes' => [
                'nullable',
                'string',
                'max:500'
            ],
        ];
    }

    /**
     * REMOVED: No status transition validation for admins
     * Admins have full control to move orders to any status they need
     */
    protected function passedValidation(): void
    {
        // Admin override: No restrictions on status transitions
        // Admins can manually fix any order state as needed
        
        // Optional: Log significant status changes for audit trail
        $order = $this->route('order');
        $newStatus = $this->status;
        $currentStatus = $order->status;
        
        if ($newStatus !== $currentStatus) {
            \Illuminate\Support\Facades\Log::info('Admin manual status change', [
                'admin_id' => $this->user()->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'from_status' => $currentStatus,
                'to_status' => $newStatus,
                'notes' => $this->notes,
            ]);
        }
    }
    
    public function messages(): array
    {
        return [
            'status.required' => 'Status is required.',
            'status.in' => 'Invalid status selected.',
            'estimated_delivery_date.required_if' => 'Estimated delivery date is required when marking as shipped.',
            'estimated_delivery_date.date' => 'Please provide a valid date.',
            'notes.max' => 'Notes cannot exceed 500 characters.',
        ];
    }
}