<?php

namespace App\Http\Requests;

use App\Models\OrderItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Check if user owns the order
        $order = $this->route('order');
        $item = $this->route('item');
        
        return $order->user_id === $this->user()->id && 
               $item->order_id === $order->id;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'product_url' => 'sometimes|required|url|max:1000',
            'quantity' => 'sometimes|required|integer|min:1|max:99',
            'tracking_number' => 'nullable|string|max:255',
            'tracking_url' => 'nullable|url|max:1000',
            'carrier' => [
                'nullable',
                Rule::in(array_keys(OrderItem::CARRIERS))
            ],
        ];
    }
}