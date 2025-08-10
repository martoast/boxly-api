<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user owns the order
        $order = $this->route('order');
        return $order && $order->user_id === $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_name' => 'required|string|max:255',
            'product_url' => 'nullable|url|max:500',
            'quantity' => 'required|integer|min:1|max:99999',
            'declared_value' => 'nullable|numeric|min:0|max:999999.99',
            'tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|url|max:500',
            'carrier' => 'nullable|string|in:ups,fedex,usps,amazon,dhl,ontrac,lasership,other,unknown',
            'proof_of_purchase' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240', // 10MB max
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'product_name.required' => 'Product name is required.',
            'product_name.max' => 'Product name cannot exceed 255 characters.',
            'product_url.url' => 'Please provide a valid URL.',
            'quantity.required' => 'Quantity is required.',
            'quantity.min' => 'Quantity must be at least 1.',
            'quantity.max' => 'Quantity cannot exceed 99,999.',
            'declared_value.numeric' => 'Declared value must be a number.',
            'declared_value.max' => 'Declared value cannot exceed $999,999.99.',
            'proof_of_purchase.file' => 'Proof of purchase must be a file.',
            'proof_of_purchase.mimes' => 'Proof of purchase must be a PDF, JPG, JPEG, or PNG file.',
            'proof_of_purchase.max' => 'Proof of purchase file cannot exceed 10MB.',
        ];
    }
}