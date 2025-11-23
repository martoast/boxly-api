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
        return [
            'guia_number' => [
                'required',
                'string',
                'regex:/^[0-9\s]+$/',
                'min:10',
                'max:30'
            ],
            'gia_file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:10240'
            ],
            'estimated_delivery_date' => [
                'required',
                'date',
                'after:today'
            ],
            // Now strictly requires Stripe Price ID
            'stripe_price_id' => [
                'required',
                'string',
                'starts_with:price_',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000'
            ]
        ];
    }

    public function messages(): array
    {
        return [
            'guia_number.required' => 'The Guia number is required to ship the order.',
            'guia_number.regex' => 'Guia number must contain only numbers and spaces.',
            'gia_file.required' => 'GIA document is required.',
            'estimated_delivery_date.required' => 'Estimated delivery date is required.',
            'stripe_price_id.required' => 'Please select a valid Box Product from the list.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('guia_number')) {
            $this->merge([
                'guia_number' => preg_replace('/\s+/', ' ', trim($this->guia_number))
            ]);
        }
    }
}