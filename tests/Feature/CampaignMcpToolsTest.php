<?php

namespace Tests\Feature;

use App\Mcp\Tools\Admin\AdminCampaignCalendarTool;
use App\Mcp\Tools\Admin\AdminScheduleCampaignTool;
use App\Models\EmailCampaign;
use App\Models\User;
use Tests\LiveShoppingTestCase;

/** The admin MCP campaign tools (2026-11-17): schedule / unschedule a draft and read the calendar, through the admin's own actions. */
class CampaignMcpToolsTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['database/migrations/2026_03_03_000000_create_email_campaigns_tables.php', 'database/migrations/2026_11_17_000000_add_scheduled_at_to_email_campaigns.php'] as $path) {
            $this->artisan('migrate', ['--path' => $path, '--force' => true]);
        }
    }

    private function run_(object $tool, array $args): array
    {
        $r = $tool->handle($args);
        $j = json_decode(json_encode($r), true);

        return ['error' => (bool) ($j['isError'] ?? false), 'text' => json_encode($j)];
    }

    public function test_an_admin_schedules_reschedules_and_unschedules_a_draft_and_sees_it_on_the_calendar(): void
    {
        $admin = User::factory()->createQuietly(['role' => 'admin']);
        $c = EmailCampaign::create(['created_by' => $admin->id, 'name' => 'BF #2', 'subject' => 's', 'body' => 'b', 'audience' => 'all']);
        $this->actingAs($admin);
        request()->setUserResolver(fn () => $admin); // how an MCP call arrives (the token's user)
        $day = now()->addDays(6)->toDateString();

        $r = $this->run_(new AdminScheduleCampaignTool, ['campaign_id' => $c->id, 'day' => $day, 'time' => '09:00']);
        $this->assertFalse($r['error'], $r['text']);
        $this->assertSame("{$day} 15:00", $c->fresh()->scheduled_at->utc()->format('Y-m-d H:i'), '09:00 central Mexico = 15:00 UTC');

        $cal = $this->run_(new AdminCampaignCalendarTool, ['from' => now()->toDateString(), 'to' => now()->addDays(30)->toDateString()]);
        $this->assertStringContainsString('BF #2', $cal['text']);

        $this->run_(new AdminScheduleCampaignTool, ['campaign_id' => $c->id, 'unschedule' => true]);
        $this->assertNull($c->fresh()->scheduled_at, 'unschedule really clears it');

        $bad = $this->run_(new AdminScheduleCampaignTool, ['campaign_id' => $c->id, 'day' => now()->subDay()->toDateString()]);
        $this->assertTrue($bad['error'], 'a past time is refused');
    }

    public function test_a_non_admin_cannot_schedule(): void
    {
        $user = User::factory()->createQuietly(['role' => 'customer']);
        $c = EmailCampaign::create(['created_by' => $user->id, 'name' => 'x', 'subject' => 's', 'body' => 'b', 'audience' => 'all']);
        $this->actingAs($user);
        request()->setUserResolver(fn () => $user);
        $r = $this->run_(new AdminScheduleCampaignTool, ['campaign_id' => $c->id, 'day' => now()->addDay()->toDateString()]);
        $this->assertTrue($r['error']);
        $this->assertNull($c->fresh()->scheduled_at);
    }
}
