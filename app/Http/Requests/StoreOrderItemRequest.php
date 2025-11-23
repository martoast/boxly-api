<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');
        
        // STRICT: Only allow adding items before processing starts
        return $order->user_id === $this->user()->id && 
               in_array($order->status, [
                   Order::STATUS_COLLECTING,
                   Order::STATUS_AWAITING_PACKAGES,
                   Order::STATUS_PACKAGES_COMPLETE
               ]);
    }

    public function rules(): array
    {
        return [
            'product_url' => 'nullable|url|max:1000',
            'product_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1|max:100',
            'declared_value' => 'required|numeric|min:0.01|max:99999.99',
            'tracking_number' => 'nullable|string|max:100',
            'tracking_url' => 'nullable|url|max:1000',
            'carrier' => 'nullable|string|max:50',
            'estimated_delivery_date' => 'nullable|date|after_or_equal:today',
            // File uploads
            'proof_of_purchase' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB
            'product_image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120', // 5MB Image
        ];
    }

    public function messages(): array
    {
        return [
            'authorize' => 'Cannot add items - order is already being processed or has been completed.',
            'product_name.required' => 'Product name is required.',
            'quantity.required' => 'Quantity is required.',
            'declared_value.required' => 'Declared value is required.',
            'proof_of_purchase.max' => 'Proof of purchase file size cannot exceed 10MB.',
            'product_image.image' => 'Product image must be an image file.',
            'product_image.max' => 'Product image size cannot exceed 5MB.',
        ];
    }
}