<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\LiveShoppingTestCase;

/**
 * /me/api-docs — the route reference generated from the live route table.
 *
 *   vendor/bin/phpunit tests/Feature/ApiDocsTest.php
 */
class ApiDocsTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_04_29_000007_add_team_to_users.php', '--force' => true]);
        // The users.role enum check (customer|admin on SQLite; prod MySQL also allows employee) would refuse staff rows.
        \Illuminate\Support\Facades\DB::statement('PRAGMA ignore_check_constraints = ON');
    }

    private function user(string $role, ?string $team = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => "{$role}{$team}@boxly.test",
            'password' => bcrypt('secret'), 'role' => $role, 'team' => $team,
        ]);
    }

    private function paths($response): array
    {
        return collect($response->json('data.groups'))->flatMap(fn ($g) => collect($g['routes'])->pluck('path'))->all();
    }

    public function test_admin_sees_admin_and_shopping_routes(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')->getJson('/me/api-docs')->assertOk();

        $paths = $this->paths($res);
        $this->assertContains('/admin/orders', $paths);
        $this->assertContains('/shopping/in-person/slots', $paths);
        $this->assertGreaterThan(100, $res->json('data.count'));
    }

    public function test_shopping_manager_sees_only_shopping_routes(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_EMPLOYEE, User::TEAM_SHOPPING), 'sanctum')->getJson('/me/api-docs')->assertOk();

        $paths = $this->paths($res);
        $this->assertContains('/shopping/in-person/slots', $paths);
        $this->assertEmpty(array_filter($paths, fn ($p) => str_starts_with($p, '/admin/')));
    }

    public function test_customer_is_refused(): void
    {
        $this->actingAs($this->user(User::ROLE_CUSTOMER), 'sanctum')->getJson('/me/api-docs')->assertStatus(403);
    }

    public function test_inline_validation_fields_are_listed(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')->getJson('/me/api-docs')->assertOk();

        // AdminInPersonController@copyWeek validates 'weeks' inline.
        $copy = collect($res->json('data.groups'))->flatMap(fn ($g) => $g['routes'])
            ->first(fn ($r) => $r['method'] === 'POST' && str_ends_with($r['path'], '/in-person/slots/copy-week'));
        $this->assertNotNull($copy);
        $this->assertArrayHasKey('weeks', $copy['fields']);
    }

    public function test_a_shopping_manager_learns_how_to_set_hours(): void
    {
        // Alex 2026-10-02: the shopping manager's agent sets her hours from these docs alone.
        $md = $this->actingAs($this->user(User::ROLE_EMPLOYEE, User::TEAM_SHOPPING), 'sanctum')
            ->get('/me/api-docs?format=md')->assertOk()->getContent();

        $this->assertStringContainsString('`PUT /shopping/in-person/slots`', $md);
        $this->assertStringContainsString('"add": [{"date": "YYYY-MM-DD", "start_time": "HH:00"}', $md);
        $this->assertStringContainsString('09:00 to 17:00', $md);
        $this->assertStringContainsString('`GET /shopping/in-person/slots` — The personal-shopping hours published', $md);
    }

    public function test_markdown_format(): void
    {
        $res = $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')->get('/me/api-docs?format=md')->assertOk();

        $this->assertStringContainsString('text/markdown', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('`GET /admin/orders`', $res->getContent());
    }

    public function test_an_admin_agent_finds_label_scans(): void
    {
        // Alex 2026-10-07: the admin's agent reads arrived packages with its API key.
        $md = $this->actingAs($this->user(User::ROLE_ADMIN), 'sanctum')->get('/me/api-docs?format=md')->assertOk()->getContent();

        $this->assertStringContainsString('`GET /admin/label-scans` — Packages that arrived at the warehouse', $md);
        $this->assertStringContainsString('since (ISO date-time', $md);
    }
}
