<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');
        
        // ONLY check if the user owns the order here.
        // Let the Controller/Model handle status checks (Empty items, Wrong status)
        // so we can return specific error messages (400) instead of generic Forbidden (403).
        return $order && $order->user_id === $this->user()->id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            // No validation rules needed here
        ];
    }
}