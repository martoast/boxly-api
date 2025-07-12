<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminSendQuoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (!$this->user()->isAdmin()) {
            return false;
        }
        
        $order = $this->route('order');
        
        // Order must be in packages_complete status and all items weighed
        return $order->status === 'packages_complete' && 
               $order->canBeQuoted();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // Quote is auto-calculated, no input needed
            // But we could allow admin to override if needed:
            'custom_shipping_cost' => 'nullable|numeric|min:1|max:99999',
            'notes' => 'nullable|string|max:500',
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'authorize' => 'Cannot send quote. Ensure all packages have arrived and been weighed.',
        ];
    }
}