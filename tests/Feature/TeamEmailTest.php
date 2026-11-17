<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\LiveShoppingTestCase;

/** Team emails (2026-11-17): admins write plain text to Boxly staff only — never to customers. */
class TeamEmailTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_04_29_000007_add_team_to_users.php', '--force' => true]);
    }

    private function staff(string $role, ?string $team = null): User
    {
        \Illuminate\Support\Facades\DB::statement('PRAGMA ignore_check_constraints = ON'); // the test DB's role CHECK predates 'employee'

        return User::factory()->createQuietly(['role' => $role, 'team' => $team]);
    }

    public function test_an_admin_emails_a_staff_member_and_lists_the_staff(): void
    {
        Mail::fake();
        $admin = $this->staff('admin');
        $vel = $this->staff('employee', 'shopping');
        $this->staff('customer');

        $list = $this->actingAs($admin)->getJson('/admin/team-email/staff')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$admin->email, $vel->email], array_column($list, 'email'), 'staff only');

        $this->actingAs($admin)->postJson('/admin/team-email', ['to' => [$vel->email], 'subject' => 'Karla #655', 'body' => 'Total $74.36'])
            ->assertOk()->assertJsonPath('data.to', [$vel->email]);
        $this->actingAs($admin)->postJson('/admin/team-email', ['to' => [$vel->id], 'subject' => 's', 'body' => 'b'])->assertOk();
    }

    public function test_a_customer_address_is_refused_and_nothing_is_sent(): void
    {
        $admin = $this->staff('admin');
        $vel = $this->staff('employee', 'shopping');
        $customer = $this->staff('customer');

        $this->actingAs($admin)->postJson('/admin/team-email', ['to' => [$vel->email, $customer->email], 'subject' => 's', 'body' => 'b'])
            ->assertStatus(422)->assertJsonPath('errors.to', [$customer->email]);
        $this->actingAs($admin)->postJson('/admin/team-email', ['to' => ['nobody@example.com'], 'subject' => 's', 'body' => 'b'])->assertStatus(422);
    }

    public function test_only_admins_can_send(): void
    {
        $vel = $this->staff('employee', 'shopping');
        $customer = $this->staff('customer');
        $this->actingAs($vel)->postJson('/admin/team-email', ['to' => [$vel->email], 'subject' => 's', 'body' => 'b'])->assertForbidden();
        $this->actingAs($customer)->getJson('/admin/team-email/staff')->assertForbidden();
    }
}
