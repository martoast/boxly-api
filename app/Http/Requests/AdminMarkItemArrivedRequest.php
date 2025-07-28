<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminMarkItemArrivedRequest extends FormRequest
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
            'arrived' => 'required|boolean',
            'weight' => 'required_if:arrived,true|nullable|numeric|min:0.01|max:999.99',
            'dimensions' => 'nullable|array',
            'dimensions.length' => 'nullable|numeric|min:1|max:999',
            'dimensions.width' => 'nullable|numeric|min:1|max:999',
            'dimensions.height' => 'nullable|numeric|min:1|max:999',
            'declared_value' => 'nullable|numeric|min:0|max:99999.99',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Only validate order status if trying to mark as arrived
            if ($this->input('arrived') === true) {
                $order = $this->route('order');
                
                if ($order && $order->status === Order::STATUS_COLLECTING) {
                    $validator->errors()->add('arrived', 'Cannot mark items as arrived. The user has not completed the order yet.');
                }
            }
        });
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'weight.required_if' => 'Weight is required when marking item as arrived.',
            'weight.min' => 'Weight must be at least 0.01 kg.',
            'declared_value.min' => 'Declared value cannot be negative.',
            'declared_value.max' => 'Declared value cannot exceed $99,999.99.',
        ];
    }
}