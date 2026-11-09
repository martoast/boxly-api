<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** boxly:assistant-health — the AI search check (2026-11-09: a provider's credits ran out and every chat failed for 3 days unnoticed). */
class AssistantHealthTest extends TestCase
{
    private const OK_STREAM = "data: {\"type\":\"start\"}\n\ndata: {\"type\":\"text-delta\",\"id\":\"0\",\"delta\":\"¡Hola! \"}\n\ndata: {\"type\":\"text-delta\",\"id\":\"0\",\"delta\":\"¿Qué buscas?\"}\n\ndata: {\"type\":\"finish\"}\n\ndata: [DONE]\n";
    private const ERROR_STREAM = "data: {\"type\":\"start\"}\n\ndata: {\"type\":\"error\",\"errorText\":\"Your prepayment credits are depleted.\"}\n\ndata: [DONE]\n";

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.assistant_health.url' => 'https://boxly.test/api/assistant', 'services.assistant_health.email' => 'ops@example.com']);
        Cache::forget('assistant_health:failing_since');
    }

    public function test_a_reply_is_healthy_and_sends_nothing(): void
    {
        Http::fake(['boxly.test/*' => Http::response(self::OK_STREAM, 200)]);
        Mail::shouldReceive('raw')->never();
        $this->artisan('boxly:assistant-health')->assertExitCode(0);
    }

    public function test_a_streamed_provider_error_emails_the_alert_address(): void
    {
        Http::fake(['boxly.test/*' => Http::response(self::ERROR_STREAM, 200)]);
        Mail::shouldReceive('raw')->once()->withArgs(fn ($text) => str_contains($text, 'Your prepayment credits are depleted.'));
        $this->artisan('boxly:assistant-health')->assertExitCode(1);
        $this->assertNotNull(Cache::get('assistant_health:failing_since'));
    }

    public function test_an_http_error_emails_and_the_next_good_run_sends_one_recovery_email(): void
    {
        Http::fakeSequence('boxly.test/*')->push('Server Error', 500)->push(self::OK_STREAM, 200)->push(self::OK_STREAM, 200);
        Mail::shouldReceive('raw')->twice();
        $this->artisan('boxly:assistant-health')->assertExitCode(1);  // the alert
        $this->artisan('boxly:assistant-health')->assertExitCode(0);  // the recovery email
        $this->artisan('boxly:assistant-health')->assertExitCode(0);  // healthy again: nothing
        $this->assertNull(Cache::get('assistant_health:failing_since'));
    }

    public function test_dry_run_never_emails(): void
    {
        Http::fake(['boxly.test/*' => Http::response(self::ERROR_STREAM, 200)]);
        Mail::shouldReceive('raw')->never();
        $this->artisan('boxly:assistant-health --dry-run')->assertExitCode(1);
    }
}
