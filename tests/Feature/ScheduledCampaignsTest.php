<?php

namespace Tests\Feature;

use App\Models\CampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\LiveShoppingTestCase;

/** Scheduled email campaigns (2026-11-17): a draft starts by itself at its scheduled_at, exactly once; a future one waits. */
class ScheduledCampaignsTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['database/migrations/2026_03_03_000000_create_email_campaigns_tables.php', 'database/migrations/2026_11_17_000000_add_scheduled_at_to_email_campaigns.php'] as $path) {
            $this->artisan('migrate', ['--path' => $path, '--force' => true]);
        }
        Queue::fake();
    }

    private function campaign(User $by, ?string $at): EmailCampaign
    {
        return EmailCampaign::create(['created_by' => $by->id, 'name' => 'BF', 'subject' => 's', 'body' => 'b', 'audience' => 'all', 'batch_size' => 50, 'batch_delay_seconds' => 60, 'scheduled_at' => $at]);
    }

    public function test_a_due_draft_starts_once_and_a_future_one_waits(): void
    {
        $admin = User::factory()->createQuietly(['role' => 'admin']);
        User::factory()->count(3)->createQuietly();
        $due = $this->campaign($admin, now()->subMinute()->toDateTimeString());
        $later = $this->campaign($admin, now()->addDay()->toDateTimeString());
        $none = $this->campaign($admin, null);

        $this->artisan('boxly:campaigns-scheduled')->assertSuccessful();
        $this->assertSame(EmailCampaign::STATUS_SENDING, $due->fresh()->status);
        $this->assertGreaterThan(0, CampaignRecipient::where('campaign_id', $due->id)->count());
        $this->assertSame(EmailCampaign::STATUS_DRAFT, $later->fresh()->status);
        $this->assertSame(EmailCampaign::STATUS_DRAFT, $none->fresh()->status);

        $n = CampaignRecipient::where('campaign_id', $due->id)->count();
        $this->artisan('boxly:campaigns-scheduled')->assertSuccessful();
        $this->assertSame($n, CampaignRecipient::where('campaign_id', $due->id)->count(), 'started once');
    }

    public function test_a_start_that_throws_is_taken_off_the_schedule_and_the_others_still_start(): void
    {
        $admin = User::factory()->createQuietly(['role' => 'admin']);
        User::factory()->count(2)->createQuietly();
        $bad = $this->campaign($admin, now()->subMinutes(2)->toDateTimeString());
        $good = $this->campaign($admin, now()->subMinute()->toDateTimeString());
        $real = app(\App\Http\Controllers\AdminCampaignController::class);
        $this->mock(\App\Http\Controllers\AdminCampaignController::class, function ($m) use ($bad, $real) {
            $m->shouldReceive('start')->andReturnUsing(function ($c) use ($bad, $real) {
                if ($c->id === $bad->id) throw new \RuntimeException('boom');
                return $real->start($c);
            });
        });
        $this->artisan('boxly:campaigns-scheduled')->expectsOutputToContain('NOT started ' . $bad->id)->assertSuccessful();
        $this->assertNull($bad->fresh()->scheduled_at, 'off the schedule, not retried every minute');
        $this->assertSame(EmailCampaign::STATUS_DRAFT, $bad->fresh()->status);
        $this->assertSame(EmailCampaign::STATUS_SENDING, $good->fresh()->status, 'the next due campaign still starts');
    }

    public function test_the_admin_schedules_a_draft_and_unschedules_it(): void
    {
        $admin = User::factory()->createQuietly(['role' => 'admin']);
        $c = $this->campaign($admin, null);
        $at = now()->addDays(2)->startOfMinute();
        $this->actingAs($admin)->putJson("/admin/campaigns/{$c->id}", ['scheduled_at' => $at->toIso8601String()])->assertOk();
        $this->assertTrue($c->fresh()->scheduled_at->equalTo($at));
        // a time with Mexico's offset is that instant, not its wall clock in UTC
        $mx = now()->addDays(3)->toDateString();
        $this->actingAs($admin)->putJson("/admin/campaigns/{$c->id}", ['scheduled_at' => "{$mx}T09:00:00-06:00"])->assertOk();
        $this->assertSame("{$mx} 15:00", $c->fresh()->scheduled_at->utc()->format('Y-m-d H:i'));
        $this->actingAs($admin)->putJson("/admin/campaigns/{$c->id}", ['scheduled_at' => now()->subHour()->toIso8601String()])->assertStatus(422);
        $this->actingAs($admin)->putJson("/admin/campaigns/{$c->id}", ['scheduled_at' => null])->assertOk();
        $this->assertNull($c->fresh()->scheduled_at);
    }

    public function test_the_calendar_lists_the_month_and_the_unscheduled_drafts(): void
    {
        $admin = User::factory()->createQuietly(['role' => 'admin']);
        $in = $this->campaign($admin, now()->addDays(3)->toDateTimeString());
        $out = $this->campaign($admin, now()->addMonths(3)->toDateTimeString());
        $draft = $this->campaign($admin, null);
        $r = $this->actingAs($admin)->getJson('/admin/campaigns/calendar?from=' . now()->toDateString() . '&to=' . now()->addDays(30)->toDateString())->assertOk();
        $ids = collect($r->json('data.campaigns'))->pluck('id')->all();
        $this->assertContains($in->id, $ids);
        $this->assertNotContains($out->id, $ids);
        $this->assertSame([$draft->id], collect($r->json('data.unscheduled_drafts'))->pluck('id')->all());
    }
}
