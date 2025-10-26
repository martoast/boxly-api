<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');
        $item = $this->route('item');
        
        // STRICT: Only allow updates before processing starts
        $validStatus = in_array($order->status, [
            Order::STATUS_COLLECTING,
            Order::STATUS_AWAITING_PACKAGES,
            Order::STATUS_PACKAGES_COMPLETE
        ]);
        
        $isOwner = $order->user_id === $this->user()->id;
        $itemBelongsToOrder = $item->order_id === $order->id;
        
        // STRICT: Users cannot modify items that have already arrived
        $canModify = !$item->arrived;
        
        return $isOwner && $itemBelongsToOrder && $validStatus && $canModify;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'product_url' => 'sometimes|nullable|url|max:500',
            'product_name' => 'sometimes|required|string|max:255',
            'quantity' => 'sometimes|required|integer|min:1|max:100',
            'declared_value' => 'sometimes|required|numeric|min:0.01|max:99999.99',
            'tracking_number' => 'sometimes|nullable|string|max:100',
            'tracking_url' => 'sometimes|nullable|url|max:500',
            'carrier' => 'sometimes|nullable|string|max:50',
            'estimated_delivery_date' => 'sometimes|nullable|date|after_or_equal:today', // NEW
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'authorize' => 'You cannot modify items that have already arrived at the warehouse or orders being processed.',
            'product_name.required' => 'Product name is required.',
            'quantity.required' => 'Quantity is required.',
            'quantity.min' => 'Quantity must be at least 1.',
            'declared_value.required' => 'Declared value is required.',
            'declared_value.min' => 'Declared value must be at least $0.01.',
            'estimated_delivery_date.date' => 'Please provide a valid delivery date.',
            'estimated_delivery_date.after_or_equal' => 'Estimated delivery date cannot be in the past.',
        ];
    }
}