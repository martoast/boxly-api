<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($this->user()->id),
            ],
            'phone' => 'nullable|string|max:20',
            'preferred_language' => 'nullable|string|in:es,en',
            'street' => 'nullable|string|max:255',
            'exterior_number' => 'nullable|string|max:20',
            'interior_number' => 'nullable|string|max:20',
            'colonia' => 'nullable|string|max:100',
            'municipio' => 'nullable|string|max:100',
            'estado' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|regex:/^\d{5}$/',
        ];
    }

    /**
     * Get custom error messages
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'This email is already in use.',
            'postal_code.regex' => 'Postal code must be 5 digits.',
        ];
    }
}