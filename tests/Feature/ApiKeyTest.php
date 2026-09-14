<?php

namespace Tests\Feature;

use App\Http\Controllers\ExtensionTokenController;
use App\Http\Controllers\McpTokenController;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LiveShoppingTestCase;

/**
 * Self-serve admin API keys (/me/api-keys) and the reserved-name rule that
 * keeps them from being destroyed by Boxly's own connection tokens.
 *
 * Builds on LiveShoppingTestCase for the same reason ConversationContextTest
 * does: it is the one base here that can run real migrations on the test
 * connection. It brings up `users`; the sanctum table is added below.
 *
 *   vendor/bin/phpunit tests/Feature/ApiKeyTest.php
 */
class ApiKeyTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--path'  => 'database/migrations/2026_04_30_000000_create_personal_access_tokens_table.php',
            '--force' => true,
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin@boxly.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN,
        ]);
    }

    private function customer(): User
    {
        return User::create([
            'name' => 'Customer', 'email' => 'customer@boxly.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_CUSTOMER,
        ]);
    }

    public function test_admin_can_create_list_and_revoke_a_key(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/me/api-keys', ['name' => 'jarvis'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'jarvis');

        $key = $created->json('data.key');
        $this->assertNotEmpty($key, 'the plaintext key is returned exactly once');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/me/api-keys')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'jarvis')
            // The secret is never readable again.
            ->assertJsonMissingPath('data.0.token');

        $id = $created->json('data.id');
        $this->actingAs($admin, 'sanctum')->deleteJson("/me/api-keys/{$id}")->assertOk();
        $this->assertCount(0, $admin->fresh()->tokens);
    }

    public function test_a_key_reaches_the_admin_surface(): void
    {
        $admin = $this->admin();
        $key = $admin->createToken('jarvis')->plainTextToken;

        // Any admin-gated route proves the guard chain; this one needs no extra
        // tables. 200 or 500 both mean "got past auth:sanctum + admin"; what
        // must never happen is 401/403.
        $status = $this->withHeader('Authorization', "Bearer {$key}")
            ->getJson('/admin/users/'.$admin->id.'/cli-tokens')
            ->getStatusCode();

        $this->assertNotContains($status, [401, 403], 'an admin key must reach /admin/*');
    }

    public function test_a_customer_key_cannot_reach_the_admin_surface(): void
    {
        $key = $this->customer()->createToken('theirs')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$key}")
            ->getJson('/me/api-keys')
            ->assertStatus(403);
    }

    public function test_a_revoked_key_stops_working(): void
    {
        $admin = $this->admin();
        $key = $admin->createToken('jarvis')->plainTextToken;
        $admin->tokens()->delete();

        $this->withHeader('Authorization', "Bearer {$key}")
            ->getJson('/me/api-keys')
            ->assertStatus(401);
    }

    #[DataProvider('reservedNames')]
    public function test_reserved_names_are_rejected(string $name): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/me/api-keys', ['name' => $name])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public static function reservedNames(): array
    {
        return [
            'mcp'       => [McpTokenController::TOKEN_NAME],
            'web chat'  => [McpTokenController::CHAT_TOKEN_NAME],
            'extension' => [ExtensionTokenController::TOKEN_NAME],
        ];
    }

    /**
     * The bug this rule exists for: McpTokenController::issue deletes every
     * token named `claude-mcp` before minting. Without the reserved-name rule
     * a user key of that name would vanish the next time they opened the AI
     * card. The key list must also never show Boxly's own tokens.
     */
    public function test_boxly_connection_tokens_do_not_disturb_user_keys(): void
    {
        $admin = $this->admin();
        $admin->createToken('jarvis');

        // Simulate opening the "Connect your AI" card.
        $this->actingAs($admin, 'sanctum')->postJson('/me/mcp-token')->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/me/api-keys')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'jarvis');
    }

    public function test_expiry_is_optional_and_honoured(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/me/api-keys', ['name' => 'no-expiry'])
            ->assertStatus(201)
            ->assertJsonPath('data.expires_at', null);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/me/api-keys', ['name' => 'short', 'expires_in_days' => 30])
            ->assertStatus(201);

        $this->assertNotNull($admin->fresh()->tokens()->where('name', 'short')->first()->expires_at);
    }
}
