<?php

namespace App\Http\Requests;

use App\Models\OrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user owns the order and it's still collecting
        $order = $this->route('order');
        
        return $order->user_id === $this->user()->id && 
               $order->status === 'collecting';
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'product_url' => 'required|url|max:1000',
            'product_name' => 'required|string|max:255', 
            'quantity' => 'required|integer|min:1|max:99',
            'declared_value' => 'required|numeric|min:0.01|max:9999.99',
            'tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|url|max:1000',
            'carrier' => [
                'nullable',
                Rule::in(array_keys(OrderItem::CARRIERS))
            ],
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'product_url.required' => 'Please provide the product URL.',
            'product_url.url' => 'Please provide a valid URL.',
            'declared_value.required' => 'Please enter the price you paid.',
            'declared_value.min' => 'Price must be at least $0.01.',
            'tracking_url.url' => 'Please provide a valid tracking URL.',
        ];
    }
}