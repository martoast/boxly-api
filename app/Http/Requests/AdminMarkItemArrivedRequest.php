<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'weight.required_if' => 'Weight is required when marking item as arrived.',
            'weight.min' => 'Weight must be at least 0.01 kg.',
        ];
    }
}