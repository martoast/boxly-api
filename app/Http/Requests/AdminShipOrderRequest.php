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
            // Support for multiple boxes - each box is a physical box that needs its own GIA
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
                'max:1', // Changed: Each box entry should be quantity 1 (expanded)
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

        // For SHIPPING orders: each box needs guia_number and gia_file
        // For CROSSING orders: these are all optional (no physical shipping/delivery)
        if ($isCrossing) {
            // Crossing orders: no GIA required
            $rules['boxes.*.guia_number'] = ['nullable', 'string'];
            $rules['boxes.*.gia_file'] = ['nullable', 'file', 'mimes:pdf', 'max:10240'];
            $rules['estimated_delivery_date'] = ['nullable', 'date'];
        } else {
            // Shipping orders: each box needs its own guia and GIA file
            $rules['boxes.*.guia_number'] = [
                'required',
                'string',
                'regex:/^[0-9\s]+$/',
                'min:10',
                'max:30'
            ];
            $rules['boxes.*.gia_file'] = [
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
            'estimated_delivery_date.required' => 'Estimated delivery date is required.',
            'stripe_price_id.required_without' => 'Please select at least one box or provide a stripe_price_id.',
            'boxes.required_without' => 'Please select at least one box.',
            'boxes.min' => 'At least one box is required.',
            'boxes.*.stripe_price_id.required_with' => 'Each box must have a valid Stripe Price ID.',
            'boxes.*.stripe_price_id.starts_with' => 'Invalid Stripe Price ID format.',
            'boxes.*.quantity.min' => 'Quantity must be at least 1.',
            'boxes.*.quantity.max' => 'Each box entry should have quantity of 1. Please create separate entries for multiple boxes.',
            // Per-box GIA validation messages
            'boxes.*.guia_number.required' => 'Each box requires a Guia number.',
            'boxes.*.guia_number.regex' => 'Guia number must contain only numbers and spaces.',
            'boxes.*.gia_file.required' => 'Each box requires a GIA document (PDF).',
            'boxes.*.gia_file.mimes' => 'GIA document must be a PDF file.',
            'boxes.*.gia_file.max' => 'GIA document must be less than 10MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalize guia numbers in each box (trim and clean up spaces)
        if ($this->has('boxes')) {
            $boxes = collect($this->boxes)->map(function ($box) {
                $normalized = [
                    'stripe_price_id' => $box['stripe_price_id'] ?? null,
                    'quantity' => $box['quantity'] ?? 1,
                ];

                // Normalize guia_number if present
                if (isset($box['guia_number'])) {
                    $normalized['guia_number'] = preg_replace('/\s+/', ' ', trim($box['guia_number']));
                }

                // gia_file is handled automatically by Laravel's file handling

                return $normalized;
            })->toArray();

            $this->merge(['boxes' => $boxes]);
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
    }
}