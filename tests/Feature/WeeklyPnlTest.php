<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\LiveShoppingTestCase;

/**
 * /admin/dashboard/v3/week — profit & loss for one calendar week (Ideas + Bugs #280):
 * ISO week, Monday–Sunday in America/Tijuana, same revenue / expense rules as the overview.
 *
 *   vendor/bin/phpunit tests/Feature/WeeklyPnlTest.php
 */
class WeeklyPnlTest extends LiveShoppingTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([
            'database/migrations/2025_11_03_043836_business_expenses.php',
            'database/migrations/2025_11_06_180935_add_is_manual_mode_to_monthly_manual_metrics_table.php',
            'database/migrations/2026_07_04_010000_add_scope_to_business_expenses_table.php',
        ] as $m) {
            $this->artisan('migrate', ['--path' => $m, '--force' => true]);
        }
        // The orders / purchase-request migration chains carry MySQL-only statements (see
        // LiveShoppingTestCase), so these two get just the columns the P&L reads.
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->default('collecting');
            $t->decimal('amount_paid', 10, 2)->nullable();
            $t->decimal('deposit_amount', 10, 2)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('deposit_paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->string('currency', 3)->default('mxn');
            $t->decimal('processing_fee', 10, 2)->default(0);
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        DB::statement('PRAGMA ignore_check_constraints = ON');
        $this->admin = User::factory()->createQuietly(['role' => 'admin']);
    }

    private function order(array $a): void
    {
        DB::table('orders')->insert($a + ['status' => 'paid', 'created_at' => $a['paid_at'] ?? $a['deposit_paid_at'] ?? now(), 'updated_at' => now()]);
    }

    private function expense(string $date, float $amount, string $category, string $scope = 'business'): void
    {
        DB::table('business_expenses')->insert([
            'scope' => $scope, 'category' => $category, 'amount' => $amount, 'currency' => 'mxn',
            'expense_date' => $date, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_week_profit_and_loss_in_tijuana_time(): void
    {
        // Week 2026-W41 = Mon 10/05 – Sun 10/11 in Tijuana (UTC−7 in October).
        $this->order(['amount_paid' => 1000, 'paid_at' => '2026-10-05 08:00:00']);   // Mon 01:00 Tijuana → in
        $this->order(['amount_paid' => 500, 'paid_at' => '2026-10-05 05:00:00']);    // Sun 10/04 22:00 Tijuana → previous week
        $this->order(['deposit_amount' => 200, 'deposit_paid_at' => '2026-10-07 18:00:00']); // deposit only → in
        $this->order(['amount_paid' => 700, 'paid_at' => '2026-10-12 08:00:00']);    // next Monday → out
        DB::table('purchase_requests')->insert(['status' => 'paid', 'currency' => 'usd', 'processing_fee' => 10, 'paid_at' => '2026-10-08 20:00:00', 'created_at' => now(), 'updated_at' => now()]); // 10 USD × 18
        $this->expense('2026-10-06', 300, 'ads');
        $this->expense('2026-10-11', 50, 'software');
        $this->expense('2026-10-06', 999, 'misc', 'personal'); // owners' own — never in business P&L
        $this->expense('2026-10-12', 400, 'ads');              // next week

        $r = $this->actingAs($this->admin)->getJson('/admin/dashboard/v3/week?week=2026-W41')->assertOk();

        $r->assertJsonPath('data.week.iso', '2026-W41')
            ->assertJsonPath('data.week.start', '2026-10-05')
            ->assertJsonPath('data.week.end', '2026-10-11')
            ->assertJsonPath('data.week.timezone', 'America/Tijuana');
        $this->assertEquals(1380, $r->json('data.revenue'));   // 1000 + 200 + 180
        $this->assertEquals(350, $r->json('data.expenses'));   // 300 + 50
        $this->assertEquals(1030, $r->json('data.profit'));
        $this->assertEquals(74.6, $r->json('data.margin'));
        $this->assertEquals(['shipping' => 1000, 'deposits' => 200, 'purchase_request_fees' => 180, 'manual' => 0], $r->json('data.revenue_breakdown'));
        $this->assertSame('ads', $r->json('data.expenses_by_category.0.category'));
        $this->assertSame(1, $r->json('data.paid_orders'));
        $this->assertCount(7, $r->json('data.per_day'));
        $this->assertEquals(1000, $r->json('data.per_day.0.revenue')); // Monday
        $this->assertEquals(1380, array_sum(array_column($r->json('data.per_day'), 'revenue')));
        $this->assertEquals(500, $r->json('data.previous_week.revenue'));
        $this->assertSame('2026-W40', $r->json('data.previous_week.week.iso'));
    }

    public function test_any_day_of_the_week_gives_that_week_and_bad_input_is_refused(): void
    {
        $this->actingAs($this->admin)->getJson('/admin/dashboard/v3/week?date=2026-10-09')->assertOk()
            ->assertJsonPath('data.week.iso', '2026-W41')->assertJsonPath('data.week.start', '2026-10-05');
        $this->actingAs($this->admin)->getJson('/admin/dashboard/v3/week')->assertOk()->assertJsonStructure(['data' => ['week' => ['iso', 'complete'], 'revenue', 'profit', 'margin', 'per_day', 'previous_week']]);
        $this->actingAs($this->admin)->getJson('/admin/dashboard/v3/week?week=2026-41')->assertStatus(422);
    }
}
