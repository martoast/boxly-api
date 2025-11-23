<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TrackBulkPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'packages' => 'required|array|min:1|max:20', // Limit to 20 to prevent timeouts
            'packages.*.tracking_number' => 'required|string|min:5|max:50',
            'packages.*.carrier' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'packages.required' => 'A list of packages is required.',
            'packages.max' => 'You can track up to 20 packages at a time.',
            'packages.*.tracking_number.required' => 'Tracking number is required for all items.',
        ];
    }
}