<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^[\+]?[(]?[0-9]{1,4}[)]?[-\s\.]?[(]?[0-9]{1,4}[)]?[-\s\.]?[0-9]{1,12}$/'
            ],
            'password' => $this->passwordRules(),
            'registration_source' => ['nullable', 'json'],
        ], [
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Please enter a valid phone number.',
            'registration_source.json' => 'Invalid tracking data format.',
        ])->validate();

        // Parse registration source if it's a string (ensure it's JSON)
        $registrationSource = null;
        if (isset($input['registration_source'])) {
            if (is_string($input['registration_source'])) {
                $decoded = json_decode($input['registration_source'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $registrationSource = json_encode($decoded);
                }
            } elseif (is_array($input['registration_source'])) {
                $registrationSource = json_encode($input['registration_source']);
            }
        }

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'user_type' => null, // No longer required
            'registration_source' => $registrationSource,
            'password' => Hash::make($input['password']),
            'preferred_language' => 'es', // Default to Spanish
        ]);
    }
}