<?php

namespace Tests\Feature;

use App\Models\ShoppingReservation;
use App\Models\ShoppingReservationSlot;
use App\Models\ShoppingSlot;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\LiveShoppingTestCase;

/**
 * /shopping/dashboard — the shopping manager's dashboard: needs-now counts, per-day numbers,
 * quote health and the in-person schedule she opened.
 *
 *   vendor/bin/phpunit tests/Feature/ShoppingDashboardTest.php
 */
class ShoppingDashboardTest extends LiveShoppingTestCase
{
    private User $velonie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_04_29_000007_add_team_to_users.php', '--force' => true]);
        // Only the columns the dashboard reads (the full purchase_requests chain isn't SQLite-safe).
        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->timestamp('quote_sent_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('purchased_at')->nullable();
            $t->timestamps();
        });
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_06_02_000001_create_purchased_products_table.php', '--force' => true]);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_30_000000_create_in_person_reservation_tables.php', '--force' => true]);
        DB::statement('PRAGMA ignore_check_constraints = ON');
        Carbon::setTestNow('2026-10-08 18:00:00'); // Thu 11:00 in San Diego
        $this->velonie = User::factory()->createQuietly(['role' => 'employee', 'team' => 'shopping']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pr(string $status, array $at = []): void
    {
        DB::table('purchase_requests')->insert($at + ['status' => $status, 'created_at' => $at['created_at'] ?? now(), 'updated_at' => now()]);
    }

    private function reservation(User $u, string $status, string $startsAtUtc, array $extra = []): ShoppingReservation
    {
        return ShoppingReservation::forceCreate($extra + [
            'reservation_number' => 'R-'.uniqid(), 'user_id' => $u->id, 'starts_at' => $startsAtUtc,
            'hours_reserved' => 1, 'status' => $status, 'amount_usd' => 25,
        ]);
    }

    public function test_dashboard_counts_what_needs_her_and_shows_her_schedule(): void
    {
        // purchase requests
        $this->pr('pending_review', ['created_at' => '2026-10-08 16:00:00']);
        $this->pr('pending_review', ['created_at' => '2026-10-07 20:00:00']);
        $this->pr('quoted', ['created_at' => '2026-10-08 10:00:00', 'quote_sent_at' => '2026-10-08 14:00:00']);  // 4 h to quote
        $this->pr('quoted', ['created_at' => '2026-10-04 10:00:00', 'quote_sent_at' => '2026-10-04 12:00:00']);  // stale (>72 h), 2 h to quote
        $this->pr('paid', ['created_at' => '2026-10-06 10:00:00', 'quote_sent_at' => '2026-10-06 16:00:00', 'paid_at' => '2026-10-08 17:00:00']); // 6 h
        $this->pr('purchased', ['created_at' => '2026-10-05 10:00:00', 'quote_sent_at' => '2026-10-05 10:00:00', 'paid_at' => '2026-10-06 10:00:00', 'purchased_at' => '2026-10-07 18:00:00']); // 0 h
        $this->pr('awaiting_deposit', ['created_at' => '2026-10-08 17:00:00']); // not in her queue yet

        // purchased products
        DB::table('purchased_products')->insert([
            ['customer_name' => 'A', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
            ['customer_name' => 'B', 'status' => 'delivered', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // in-person: Fri 10/09 10:00 + 11:00 and Thu 10/15 12:00 (San Diego, UTC−7) opened; 10:00 booked
        $fri10 = ShoppingSlot::create(['starts_at' => '2026-10-09 17:00:00']);
        ShoppingSlot::create(['starts_at' => '2026-10-09 18:00:00']);
        ShoppingSlot::create(['starts_at' => '2026-10-15 19:00:00']);
        $customer = User::factory()->createQuietly(['name' => 'Mariana López']);
        $booked = $this->reservation($customer, 'confirmed', '2026-10-09 17:00:00', ['paid_at' => '2026-10-08 15:00:00']);
        ShoppingReservationSlot::create(['reservation_id' => $booked->id, 'shopping_slot_id' => $fri10->id, 'active_slot_id' => $fri10->id]);
        $this->reservation($customer, 'completed', '2026-10-03 17:00:00', ['completed_at' => '2026-10-03 20:00:00']);      // final invoice not sent
        $this->reservation($customer, 'slot_taken', '2026-10-10 17:00:00', ['paid_at' => '2026-10-07 15:00:00']);         // refund owed

        $r = $this->actingAs($this->velonie)
            ->getJson('/shopping/dashboard?since=2026-10-05T07:00:00Z&until=2026-10-09T07:00:00Z')->assertOk();

        $this->assertSame([
            'to_quote' => 2, 'awaiting_payment' => 2, 'awaiting_payment_stale' => 1, 'to_buy' => 1,
            'not_delivered' => 1, 'reservations_upcoming' => 1, 'final_invoices_to_send' => 1, 'refunds_pending' => 1,
        ], $r->json('data.needs_now'));

        $days = collect($r->json('data.per_day'))->keyBy('day');
        $this->assertSame(['day' => '2026-10-08', 'received' => 2, 'quoted' => 1, 'paid' => 1, 'purchased' => 0, 'reservations' => 1], $days['2026-10-08']);
        $this->assertSame(1, $days['2026-10-07']['purchased']);           // 18:00 UTC = 11:00 San Diego, 10/07
        $this->assertSame(1, $days['2026-10-07']['reservations']);        // the slot_taken one was paid 10/07
        $this->assertSame(1, $days['2026-10-07']['received']);            // created 10/07 20:00 UTC
        $this->assertArrayNotHasKey('2026-10-04', $days->all());          // before the window

        // health: 4 quotes sent in 30 days, (4+2+6+0)/4 = 3 h, 2 of 4 paid
        $this->assertSame(['quotes_sent_30d' => 4, 'avg_hours_to_quote' => 3, 'quote_conversion' => 50], $r->json('data.health'));

        // schedule: 14 days from today; Friday has 2 opened hours, 10:00 booked by Mariana
        $schedule = collect($r->json('data.schedule'))->keyBy('date');
        $this->assertCount(14, $schedule);
        $fri = $schedule['2026-10-09'];
        $this->assertSame(['open' => 1, 'booked' => 1], ['open' => $fri['open'], 'booked' => $fri['booked']]);
        $this->assertSame(['time' => '10:00', 'booked' => true, 'customer' => 'Mariana', 'reservation_number' => $booked->reservation_number], $fri['hours'][0]);
        $this->assertSame('11:00', $fri['hours'][1]['time']);
        $this->assertFalse($fri['hours'][1]['booked']);
        $this->assertSame(1, $schedule['2026-10-15']['open']);
        $this->assertSame(0, $schedule['2026-10-08']['open'] + $schedule['2026-10-08']['booked']);
    }

    public function test_works_without_a_window_on_immutable_dates(): void
    {
        // Production runs Date::use(CarbonImmutable) — now() is immutable there; the default window
        // (no since/until) crashed on a mutable-only type hint until this test.
        \Illuminate\Support\Facades\Date::use(\Carbon\CarbonImmutable::class);
        try {
            $this->pr('pending_review', ['created_at' => '2026-10-08 16:00:00']);
            $this->actingAs($this->velonie)->getJson('/shopping/dashboard')->assertOk()
                ->assertJsonPath('data.needs_now.to_quote', 1)
                ->assertJsonCount(14, 'data.schedule');
        } finally {
            \Illuminate\Support\Facades\Date::useDefault();
        }
    }

    public function test_customers_cannot_see_it(): void
    {
        $this->actingAs(User::factory()->createQuietly(['role' => 'customer']))->getJson('/shopping/dashboard')->assertStatus(403);
    }
}
