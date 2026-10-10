<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\LiveShoppingTestCase;

/**
 * Affiliate referrals: a signup that arrives through an affiliate link (?ref=CODE)
 * is credited to that affiliate — by email/password AND by Google/Facebook (the
 * social path never credited anyone before 2026-10-08).
 *
 *   vendor/bin/phpunit tests/Feature/AffiliateReferralTest.php
 */
class AffiliateReferralTest extends LiveShoppingTestCase
{
    private Affiliate $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2025_12_17_225918_create_affiliate_tables.php', '--force' => true]);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_10_000000_add_terms_acceptance_to_users.php', '--force' => true]);
        $owner = User::factory()->createQuietly(['email' => 'alma@example.com']);
        $this->affiliate = Affiliate::create(['user_id' => $owner->id, 'affiliate_code' => 'ALMA69']);
    }

    private function socialLogin(string $email, array $state)
    {
        $su = (new SocialiteUser())->map(['email' => $email, 'name' => 'Nueva Clienta']);
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($su);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        return $this->get('/auth/google/callback?state='.urlencode(base64_encode(json_encode($state))));
    }

    public function test_email_signup_with_an_affiliate_code_is_credited(): void
    {
        $user = app(CreateNewUser::class)->create([
            'name' => 'Cliente Referido', 'email' => 'referido@example.com', 'phone' => '+526641234567',
            'password' => 'Secreto!2026x', 'password_confirmation' => 'Secreto!2026x',
            'referred_by' => 'alma69', // case-insensitive, as typed in a link
            'agree_to_terms' => true,
        ]);

        $ref = AffiliateReferral::where('user_id', $user->id)->first();
        $this->assertNotNull($ref);
        $this->assertSame($this->affiliate->id, $ref->affiliate_id);
        $this->assertSame('ALMA69', $ref->referral_code_used);
    }

    public function test_google_signup_with_an_affiliate_code_is_credited(): void
    {
        $this->socialLogin('google-nuevo@example.com', ['redirect' => null, 'ref' => 'ALMA69'])->assertRedirect();

        $user = User::where('email', 'google-nuevo@example.com')->firstOrFail();
        $this->assertSame($this->affiliate->id, AffiliateReferral::where('user_id', $user->id)->value('affiliate_id'));
    }

    public function test_google_login_of_an_existing_user_is_not_credited(): void
    {
        $existing = User::factory()->createQuietly(['email' => 'ya-existe@example.com', 'phone' => '+526640000000']);
        $this->socialLogin('ya-existe@example.com', ['ref' => 'ALMA69'])->assertRedirect();

        $this->assertFalse(AffiliateReferral::where('user_id', $existing->id)->exists());
    }

    public function test_unknown_code_and_self_referral_credit_nobody(): void
    {
        $this->socialLogin('sin-codigo@example.com', ['ref' => 'NOEXISTE1'])->assertRedirect();
        $this->assertSame(0, AffiliateReferral::count());

        $this->assertNull(\App\Http\Controllers\AffiliateController::trackReferral($this->affiliate->user_id, 'ALMA69'));
        $this->assertSame(0, AffiliateReferral::count());
    }
}
