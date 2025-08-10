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
                    Order::STATUS_COLLECTING,
                    Order::STATUS_AWAITING_PACKAGES,
                    Order::STATUS_PACKAGES_COMPLETE,
                    Order::STATUS_PROCESSING,
                    Order::STATUS_QUOTE_SENT,
                    Order::STATUS_PAID,
                    Order::STATUS_SHIPPED,
                    Order::STATUS_DELIVERED,
                    Order::STATUS_CANCELLED,
                ])
            ],
            'estimated_delivery_date' => [
                'required_if:status,' . Order::STATUS_SHIPPED,
                'nullable',
                'date',
                'after:today'
            ],
            'notes' => [
                'nullable',
                'string',
                'max:500'
            ],
            'quote_amount' => [
                'required_if:status,' . Order::STATUS_QUOTE_SENT,
                'nullable',
                'numeric',
                'min:0',
                'max:999999.99'
            ],
            'payment_link' => [
                'required_if:status,' . Order::STATUS_QUOTE_SENT,
                'nullable',
                'url'
            ],
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Status is required.',
            'status.in' => 'Invalid status selected.',
            'estimated_delivery_date.required_if' => 'Estimated delivery date is required when marking as shipped.',
            'estimated_delivery_date.after' => 'Estimated delivery date must be in the future.',
            'estimated_delivery_date.date' => 'Please provide a valid date.',
            'notes.max' => 'Notes cannot exceed 500 characters.',
            'quote_amount.required_if' => 'Quote amount is required when sending a quote.',
            'quote_amount.numeric' => 'Quote amount must be a valid number.',
            'quote_amount.min' => 'Quote amount must be at least 0.',
            'quote_amount.max' => 'Quote amount cannot exceed 999,999.99.',
            'payment_link.required_if' => 'Payment link is required when sending a quote.',
            'payment_link.url' => 'Please provide a valid URL for the payment link.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean up the status value if needed
        if ($this->has('status')) {
            $this->merge([
                'status' => strtolower(trim($this->status))
            ]);
        }

        // Convert date format if needed
        if ($this->has('estimated_delivery_date') && $this->estimated_delivery_date) {
            try {
                $date = \Carbon\Carbon::parse($this->estimated_delivery_date);
                $this->merge([
                    'estimated_delivery_date' => $date->format('Y-m-d')
                ]);
            } catch (\Exception $e) {
                // If date parsing fails, let validation handle it
            }
        }
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'status' => 'order status',
            'estimated_delivery_date' => 'estimated delivery date',
            'notes' => 'notes',
            'quote_amount' => 'quote amount',
            'payment_link' => 'payment link',
        ];
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation(): void
    {
        // Additional business logic validation
        $order = $this->route('order');
        $newStatus = $this->status;
        $currentStatus = $order->status;

        // Define valid status transitions
        $validTransitions = [
            Order::STATUS_COLLECTING => [Order::STATUS_AWAITING_PACKAGES, Order::STATUS_CANCELLED],
            Order::STATUS_AWAITING_PACKAGES => [Order::STATUS_PACKAGES_COMPLETE, Order::STATUS_COLLECTING, Order::STATUS_CANCELLED],
            Order::STATUS_PACKAGES_COMPLETE => [Order::STATUS_PROCESSING, Order::STATUS_CANCELLED],
            Order::STATUS_PROCESSING => [Order::STATUS_QUOTE_SENT, Order::STATUS_CANCELLED],
            Order::STATUS_QUOTE_SENT => [Order::STATUS_PAID, Order::STATUS_CANCELLED],
            Order::STATUS_PAID => [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED],
            Order::STATUS_SHIPPED => [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED],
            Order::STATUS_DELIVERED => [], // Terminal status
            Order::STATUS_CANCELLED => [], // Terminal status
        ];

        // Check if the transition is valid
        if (!in_array($newStatus, $validTransitions[$currentStatus] ?? [])) {
            // Allow same status (no change)
            if ($newStatus !== $currentStatus) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => ["Cannot transition from '{$currentStatus}' to '{$newStatus}'. Invalid status progression."]
                ]);
            }
        }

        // Additional checks for specific transitions
        if ($newStatus === Order::STATUS_PACKAGES_COMPLETE) {
            // Check if all items have arrived
            $allArrived = $order->items()->where('arrived', false)->count() === 0;
            if (!$allArrived) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'status' => ['Cannot mark as packages complete. Not all items have arrived.']
                ]);
            }
        }

        if ($newStatus === Order::STATUS_SHIPPED && !$order->amount_paid) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => ['Cannot mark as shipped. Order has not been paid.']
            ]);
        }
    }
}