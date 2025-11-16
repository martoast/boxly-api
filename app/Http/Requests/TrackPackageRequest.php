<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrackPackageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Public endpoint - anyone can track
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'tracking_number' => 'required|string|min:5|max:50',
            'carrier' => 'nullable|string|max:50',
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'tracking_number.required' => 'Please provide a tracking number',
            'tracking_number.min' => 'Tracking number must be at least 5 characters',
            'tracking_number.max' => 'Tracking number cannot exceed 50 characters',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'tracking_number' => 'tracking number',
            'carrier' => 'carrier',
        ];
    }
}