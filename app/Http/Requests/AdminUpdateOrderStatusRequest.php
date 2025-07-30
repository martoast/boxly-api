<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateOrderStatusRequest extends FormRequest
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
            'status' => [
                'required',
                Rule::in([
                    Order::STATUS_SHIPPED,
                    Order::STATUS_DELIVERED,
                ])
            ],
            'estimated_delivery_date' => 'required_if:status,shipped|nullable|date|after:today',
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'estimated_delivery_date.required_if' => 'Estimated delivery date is required when marking as shipped.',
        ];
    }
}