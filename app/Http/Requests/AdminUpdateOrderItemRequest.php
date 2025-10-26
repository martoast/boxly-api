<?php

namespace App\Http\Requests;

use App\Models\OrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'product_url' => 'sometimes|required|url|max:1000',
            'product_name' => 'sometimes|required|string|max:255',
            'product_image_url' => 'nullable|url|max:1000',
            'retailer' => 'nullable|string|max:100',
            'quantity' => 'sometimes|required|integer|min:1|max:999',
            'declared_value' => 'nullable|numeric|min:0|max:99999.99',
            'tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|url|max:1000',
            'carrier' => [
                'nullable',
                Rule::in(array_keys(OrderItem::CARRIERS))
            ],
            'estimated_delivery_date' => 'nullable|date', // NEW - Admin can set past dates
            'arrived' => 'sometimes|boolean',
            'arrived_at' => 'nullable|date',
            'weight' => 'nullable|numeric|min:0.01|max:999.99',
            'dimensions' => 'nullable|array',
            'dimensions.length' => 'nullable|numeric|min:1|max:999',
            'dimensions.width' => 'nullable|numeric|min:1|max:999',
            'dimensions.height' => 'nullable|numeric|min:1|max:999',
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'product_url.url' => 'Please provide a valid product URL.',
            'product_image_url.url' => 'Please provide a valid image URL.',
            'tracking_url.url' => 'Please provide a valid tracking URL.',
            'declared_value.min' => 'Declared value cannot be negative.',
            'declared_value.max' => 'Declared value cannot exceed $99,999.99.',
            'weight.min' => 'Weight must be at least 0.01 kg.',
            'weight.max' => 'Weight cannot exceed 999.99 kg.',
            'quantity.min' => 'Quantity must be at least 1.',
            'quantity.max' => 'Quantity cannot exceed 999.',
            'estimated_delivery_date.date' => 'Please provide a valid delivery date.',
        ];
    }
}