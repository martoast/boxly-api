<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminShipOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $order = $this->route('order');
        $isCrossing = $order && $order->isCrossingOnly();

        $rules = [
            // NEW: Support for multiple boxes
            // Admin can send either a single stripe_price_id OR an array of boxes
            'boxes' => [
                'required_without:stripe_price_id',
                'array',
                'min:1',
            ],
            'boxes.*.stripe_price_id' => [
                'required_with:boxes',
                'string',
                'starts_with:price_',
            ],
            'boxes.*.quantity' => [
                'sometimes',
                'integer',
                'min:1',
                'max:10',
            ],

            // Legacy single box support (backwards compatible)
            'stripe_price_id' => [
                'required_without:boxes',
                'string',
                'starts_with:price_',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000'
            ]
        ];

        // For SHIPPING orders: guia, gia_file, and estimated_delivery_date are required
        // For CROSSING orders: these are all optional (no physical shipping/delivery)
        if ($isCrossing) {
            $rules['guia_number'] = ['nullable', 'string'];
            $rules['gia_file'] = ['nullable', 'file', 'mimes:pdf', 'max:10240'];
            $rules['estimated_delivery_date'] = ['nullable', 'date'];
        } else {
            $rules['guia_number'] = [
                'required',
                'string',
                'regex:/^[0-9\s]+$/',
                'min:10',
                'max:30'
            ];
            $rules['gia_file'] = [
                'required',
                'file',
                'mimes:pdf',
                'max:10240'
            ];
            $rules['estimated_delivery_date'] = [
                'required',
                'date',
                'after:today'
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'guia_number.required' => 'The Guia number is required to ship the order.',
            'guia_number.regex' => 'Guia number must contain only numbers and spaces.',
            'gia_file.required' => 'GIA document is required.',
            'estimated_delivery_date.required' => 'Estimated delivery date is required.',
            'stripe_price_id.required_without' => 'Please select at least one box or provide a stripe_price_id.',
            'boxes.required_without' => 'Please select at least one box.',
            'boxes.min' => 'At least one box is required.',
            'boxes.*.stripe_price_id.required_with' => 'Each box must have a valid Stripe Price ID.',
            'boxes.*.stripe_price_id.starts_with' => 'Invalid Stripe Price ID format.',
            'boxes.*.quantity.min' => 'Quantity must be at least 1.',
            'boxes.*.quantity.max' => 'Quantity cannot exceed 10.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('guia_number')) {
            $this->merge([
                'guia_number' => preg_replace('/\s+/', ' ', trim($this->guia_number))
            ]);
        }

        // Convert single stripe_price_id to boxes array format for unified processing
        // This makes the controller logic simpler
        if ($this->has('stripe_price_id') && !$this->has('boxes')) {
            $this->merge([
                'boxes' => [
                    ['stripe_price_id' => $this->stripe_price_id, 'quantity' => 1]
                ]
            ]);
        }

        // Ensure each box has a default quantity of 1
        if ($this->has('boxes')) {
            $boxes = collect($this->boxes)->map(function ($box) {
                return [
                    'stripe_price_id' => $box['stripe_price_id'],
                    'quantity' => $box['quantity'] ?? 1,
                ];
            })->toArray();
            $this->merge(['boxes' => $boxes]);
        }
    }
}