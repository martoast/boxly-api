<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'product_url' => 'nullable|url|max:500',
            'product_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1|max:100',
            'declared_value' => 'required|numeric|min:0.01|max:99999.99',
            'tracking_number' => 'nullable|string|max:100',
            'tracking_url' => 'nullable|url|max:500',
            'carrier' => 'nullable|string|max:50',
            'proof_of_purchase' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240', // 10MB max
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'authorize' => 'Cannot add items - order is already being processed or has been completed.',
            'product_name.required' => 'Product name is required.',
            'quantity.required' => 'Quantity is required.',
            'quantity.min' => 'Quantity must be at least 1.',
            'declared_value.required' => 'Declared value is required.',
            'declared_value.min' => 'Declared value must be at least $0.01.',
            'proof_of_purchase.mimes' => 'Proof of purchase must be a JPG, PNG, or PDF file.',
            'proof_of_purchase.max' => 'Proof of purchase file size cannot exceed 10MB.',
        ];
    }
}