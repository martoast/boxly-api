<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class CompleteOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');
        
        // Check if user owns the order and it's in the correct status
        return $order->user_id === $this->user()->id && 
               $order->status === Order::STATUS_COLLECTING &&  // Changed to 'collecting'
               $order->items()->count() > 0;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // No additional data needed, just authorization
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'authorize' => 'You cannot complete this order.',
        ];
    }
}