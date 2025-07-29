<?php

namespace App\Http\Requests;

use App\Models\OrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

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
            'product_url' => 'nullable|url|max:1000',
            'product_name' => 'required|string|max:255', 
            'quantity' => 'required|integer|min:1|max:99',
            'declared_value' => 'nullable|numeric|min:0|max:999999.99',
            'tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|url|max:1000',
            'carrier' => [
                'nullable',
                Rule::in(array_keys(OrderItem::CARRIERS))
            ],
            'proof_of_purchase' => [
                'nullable',
                'file',
                File::types(['pdf', 'png', 'jpg', 'jpeg'])
                    ->max(10 * 1024) // 10MB
            ],
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'product_name.required' => 'Please provide the product name.',
            'quantity.required' => 'Please specify the quantity.',
            'quantity.integer' => 'Quantity must be a whole number.',
            'quantity.min' => 'Quantity must be at least 1.',
            'product_url.url' => 'Please provide a valid URL.',
            'tracking_url.url' => 'Please provide a valid tracking URL.',
            'proof_of_purchase.file' => 'The proof of purchase must be a valid file.',
            'proof_of_purchase.max' => 'The proof of purchase file may not be greater than 10 megabytes.',
        ];
    }
}