<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\LiveShoppingTestCase;

/**
 * Terms of Service acceptance (2026-10-10 legal update): a new account must tick the box and records the
 * version + date; a Google/Facebook account accepts on complete-profile; existing accounts get no
 * fictitious acceptance.
 *
 *   vendor/bin/phpunit tests/Feature/TermsAcceptanceTest.php
 */
class TermsAcceptanceTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_10_000000_add_terms_acceptance_to_users.php', '--force' => true]);
    }

    private function signup(array $extra = []): User
    {
        return app(CreateNewUser::class)->create($extra + [
            'name' => 'Cliente Nuevo', 'email' => 'nuevo'.uniqid().'@example.com', 'phone' => '+526641234567',
            'password' => 'Secreto!2026x', 'password_confirmation' => 'Secreto!2026x',
        ]);
    }

    public function test_signup_requires_the_box_and_records_version_and_date(): void
    {
        try {
            $this->signup();
            $this->fail('signing up without accepting the terms must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('agree_to_terms', $e->errors());
        }

        $user = $this->signup(['agree_to_terms' => true]);
        $this->assertSame(User::TERMS_VERSION, $user->terms_version);
        $this->assertNotNull($user->terms_accepted_at);
    }

    public function test_social_signup_accepts_on_complete_profile_and_old_accounts_stay_unaccepted(): void
    {
        $old = User::factory()->createQuietly(['phone' => null]);
        $this->assertNull($old->terms_version); // no fictitious acceptance for existing accounts

        $this->actingAs($old)->putJson('/profile', ['phone' => '+526640000000'])->assertOk();
        $this->assertNull($old->fresh()->terms_version); // a profile edit without the box accepts nothing

        $this->actingAs($old)->putJson('/profile', ['phone' => '+526640000000', 'agree_to_terms' => true])->assertOk();
        $this->assertSame(User::TERMS_VERSION, $old->fresh()->terms_version);
        $this->assertNotNull($old->fresh()->terms_accepted_at);
    }
}
