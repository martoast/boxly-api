<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $order = $this->route('order');
        
        // Check if user owns the order and it's still in collecting status
        // Orders can only be updated while still collecting items
        return $order->user_id === $this->user()->id && 
               $order->status === Order::STATUS_COLLECTING;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'delivery_address.street' => 'sometimes|required|string|max:255',
            'delivery_address.exterior_number' => 'sometimes|required|string|max:20',
            'delivery_address.interior_number' => 'nullable|string|max:20',
            'delivery_address.colonia' => 'sometimes|required|string|max:100',
            'delivery_address.municipio' => 'sometimes|required|string|max:100',
            'delivery_address.estado' => 'sometimes|required|string|max:100',
            'delivery_address.postal_code' => 'sometimes|required|string|regex:/^\d{5}$/',
            'delivery_address.referencias' => 'nullable|string|max:500',
            'is_rural' => 'sometimes|boolean',
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'delivery_address.postal_code.regex' => 'The postal code must be 5 digits',
            'delivery_address.street.required' => 'Street address is required',
            'delivery_address.colonia.required' => 'Colonia is required',
            'delivery_address.municipio.required' => 'Municipio is required',
            'delivery_address.estado.required' => 'Estado is required',
            'authorize' => 'You can only update orders that are still collecting items',
        ];
    }
}