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
            'user_type' => [
                'required',
                'string',
                Rule::in(['expat', 'business', 'shopper'])
            ],
            'password' => $this->passwordRules(),
            'registration_source' => ['nullable', 'json'], // Now expects JSON
        ], [
            'phone.required' => 'Phone number is required.',
            'phone.regex' => 'Please enter a valid phone number.',
            'user_type.required' => 'Please select your account type.',
            'user_type.in' => 'Please select a valid account type.',
            'registration_source.json' => 'Invalid tracking data format.',
        ])->validate();

        // Parse registration source if it's a string (ensure it's JSON)
        $registrationSource = null;
        if (isset($input['registration_source'])) {
            if (is_string($input['registration_source'])) {
                $decoded = json_decode($input['registration_source'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $registrationSource = json_encode($decoded); // Re-encode to ensure proper format
                }
            } elseif (is_array($input['registration_source'])) {
                $registrationSource = json_encode($input['registration_source']);
            }
        }

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'],
            'user_type' => $input['user_type'],
            'registration_source' => $registrationSource,
            'password' => Hash::make($input['password']),
            'preferred_language' => $input['user_type'] === 'expat' ? 'en' : 'es',
        ]);
    }
}