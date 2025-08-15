<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminShipOrderRequest extends FormRequest
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
            'dhl_waybill_number' => [
                'required',
                'string',
                'regex:/^[0-9\s]+$/', // Allow numbers and spaces
                'min:10',
                'max:20'
            ],
            'gia_file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:20480' // 20MB max
            ],
            'estimated_delivery_date' => [
                'required',
                'date',
                'after:today'
            ],
            'notes' => [
                'nullable',
                'string',
                'max:1000'
            ]
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'dhl_waybill_number.required' => 'DHL waybill number is required to ship the order.',
            'dhl_waybill_number.regex' => 'DHL waybill number must contain only numbers and spaces.',
            'dhl_waybill_number.min' => 'DHL waybill number must be at least 10 characters.',
            'dhl_waybill_number.max' => 'DHL waybill number cannot exceed 20 characters.',
            'gia_file.required' => 'GIA document is required to ship the order.',
            'gia_file.file' => 'GIA must be a valid file.',
            'gia_file.mimes' => 'GIA document must be a PDF file.',
            'gia_file.max' => 'GIA document cannot exceed 20MB.',
            'estimated_delivery_date.required' => 'Estimated delivery date is required.',
            'estimated_delivery_date.after' => 'Estimated delivery date must be in the future.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean up the waybill number (remove extra spaces but keep single spaces)
        if ($this->has('dhl_waybill_number')) {
            $this->merge([
                'dhl_waybill_number' => preg_replace('/\s+/', ' ', trim($this->dhl_waybill_number))
            ]);
        }
    }
}