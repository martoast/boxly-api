<?php

namespace Tests\Feature;

use App\Models\LabelScan;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\LiveShoppingTestCase;

/**
 * Label scans: /admin/label-scans and /employee/label-scans (warehouse upload of label photos).
 *
 *   vendor/bin/phpunit tests/Feature/LabelScanTest.php
 */
class LabelScanTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_04_29_000007_add_team_to_users.php', '--force' => true]);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_07_000000_create_label_scans_table.php', '--force' => true]);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_04_30_000000_create_personal_access_tokens_table.php', '--force' => true]);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_09_000000_add_warehouse_locations.php', '--force' => true]);
        Storage::fake('spaces');
    }

    private function user(string $role): User
    {
        return User::factory()->createQuietly(['role' => $role]);
    }

    /** A real 8x8 JPEG: the test PHP has no GD, so UploadedFile::fake()->image() cannot draw one. */
    private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAAIAAgBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';

    private function upload(User $u, string $prefix, array $packages)
    {
        return $this->actingAs($u)->post("/{$prefix}/label-scans", [
            'image'    => UploadedFile::fake()->createWithContent('label.jpg', base64_decode(self::JPEG)),
            'batch'    => 'b1',
            'packages' => json_encode($packages),
        ], ['Accept' => 'application/json']);
    }

    public function test_admin_uploads_one_photo_and_gets_a_row_per_package(): void
    {
        $admin = $this->user('admin');
        $r = $this->upload($admin, 'admin', [
            ['tracking_number' => '1Z07F8A70396079847', 'carrier' => 'ups', 'recipient_name' => 'BOXLY VASCO BAUTISTA', 'barcodes' => ['1Z07F8A70396079847'], 'confidence' => 'high'],
            ['tracking_number' => null, 'recipient_name' => 'BOXLY Vasco Bautista', 'needs_check' => true],
        ])->assertStatus(201);

        $this->assertCount(2, $r->json('data'));
        $this->assertSame(2, LabelScan::count());
        $first = LabelScan::orderBy('id')->first();
        $this->assertSame('1Z07F8A70396079847', $first->tracking_number);
        $this->assertSame(['1Z07F8A70396079847'], $first->barcodes);
        $this->assertFalse($first->needs_check);
        $this->assertTrue(LabelScan::orderByDesc('id')->first()->needs_check);
        $this->assertSame($first->image_path, LabelScan::orderByDesc('id')->first()->image_path); // one photo, two rows
        Storage::disk('spaces')->assertExists($first->image_path);
        $this->assertSame($admin->id, $first->created_by);
    }

    public function test_index_searches_by_name_or_tracking_and_filters_needs_check(): void
    {
        $admin = $this->user('admin');
        $this->upload($admin, 'admin', [['tracking_number' => '9400150106151227876306', 'recipient_name' => 'MARCELA TALAVERA MENDOZA']]);
        $this->upload($admin, 'admin', [['tracking_number' => null, 'recipient_name' => 'Sandra Casas', 'needs_check' => true]]);

        $this->actingAs($admin)->getJson('/admin/label-scans?search=marcela')->assertOk()->assertJsonPath('data.total', 1);
        $this->actingAs($admin)->getJson('/admin/label-scans?search=94001501')->assertOk()->assertJsonPath('data.total', 1);
        $this->actingAs($admin)->getJson('/admin/label-scans?needs_check=1')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.recipient_name', 'Sandra Casas');
        // newest first
        $this->actingAs($admin)->getJson('/admin/label-scans')->assertJsonPath('data.data.0.recipient_name', 'Sandra Casas');
    }

    public function test_until_bounds_the_list_and_stats_count_per_warehouse_day(): void
    {
        $emp = $this->user('admin'); // /employee mounts the same controller; the test DB's role column predates 'employee'
        $this->upload($emp, 'admin', [['tracking_number' => 'A1', 'recipient_name' => 'Uno']]);
        $this->upload($emp, 'admin', [['tracking_number' => 'A2', 'recipient_name' => 'Dos', 'needs_check' => true]]);
        $this->upload($emp, 'admin', [['tracking_number' => 'A3', 'recipient_name' => 'Tres']]);
        // 10/07 23:30 and 10/08 06:00 UTC: both 10/07 in San Diego; 10/08 20:00 UTC is 10/08 there
        LabelScan::where('tracking_number', 'A1')->update(['created_at' => '2026-10-07 23:30:00']);
        LabelScan::where('tracking_number', 'A2')->update(['created_at' => '2026-10-08 06:00:00']);
        LabelScan::where('tracking_number', 'A3')->update(['created_at' => '2026-10-08 20:00:00']);

        $this->actingAs($emp)->getJson('/admin/label-scans?since=2026-10-07T07:00:00Z&until=2026-10-08T07:00:00Z')
            ->assertOk()->assertJsonPath('data.total', 2);

        $this->actingAs($emp)->getJson('/admin/label-scans/stats?since=2026-10-01T07:00:00Z&until=2026-10-15T07:00:00Z')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.needs_check', 1)
            ->assertJsonPath('data.per_day', [['day' => '2026-10-07', 'count' => 2], ['day' => '2026-10-08', 'count' => 1]]);

        $this->actingAs($emp)->getJson('/admin/label-scans/stats')->assertStatus(422); // since/until required
    }

    public function test_stats_count_per_operator_and_for_one_day(): void
    {
        $mau = $this->user('admin');
        $other = $this->user('admin');
        $this->upload($mau, 'admin', [['tracking_number' => 'M1', 'recipient_name' => 'Uno']]);
        $this->upload($mau, 'admin', [['tracking_number' => 'M2', 'recipient_name' => 'Dos']]);
        $this->upload($other, 'admin', [['tracking_number' => 'O1', 'recipient_name' => 'Tres']]);
        // M1 and O1 on 10/08 in San Diego; M2 late 10/07 there (10/08 06:30 UTC)
        LabelScan::where('tracking_number', 'M1')->update(['created_at' => '2026-10-08 18:00:00']);
        LabelScan::where('tracking_number', 'O1')->update(['created_at' => '2026-10-08 19:00:00']);
        LabelScan::where('tracking_number', 'M2')->update(['created_at' => '2026-10-08 06:30:00']);

        // "how many did Mau scan on 10/08?" — the 06:30 UTC one belongs to 10/07 in San Diego
        $this->actingAs($mau)->getJson("/admin/label-scans/stats?day=2026-10-08&operator={$mau->id}")->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.operator', $mau->id)
            ->assertJsonPath('data.window.since', '2026-10-08T07:00:00+00:00');

        // the whole day, everyone: per-operator breakdown, most first
        $r = $this->actingAs($mau)->getJson('/admin/label-scans/stats?day=2026-10-08')->assertOk();
        $this->assertSame(2, $r->json('data.total'));
        $this->assertEqualsCanonicalizing([$mau->id, $other->id], array_column($r->json('data.per_operator'), 'user_id'));

        // a range, one operator: both of Mau's
        $this->actingAs($mau)->getJson("/admin/label-scans/stats?since=2026-10-07T07:00:00Z&until=2026-10-09T07:00:00Z&operator={$mau->id}")
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.per_operator.0.count', 2);

        // the list filters by operator too
        $this->actingAs($mau)->getJson("/admin/label-scans?operator={$other->id}")->assertJsonPath('data.total', 1);
    }

    public function test_operator_map_data_never_carries_money(): void
    {
        $clean = \App\Http\Controllers\UnifiedAdminDashboardController::withoutMoney([
            'success' => true,
            'data' => [
                'states' => [['code' => 'BC', 'customers' => 3, 'orders' => 5, 'revenue' => 1200.5]],
                'cities' => [['city' => 'Tijuana', 'orders' => 5, 'revenue' => 1200.5, 'lat' => 32.5]],
                'clients' => [['id' => 1, 'name' => 'Cliente', 'orders' => 2, 'revenue' => 800]],
                'totals' => ['customers' => 3, 'orders' => 5, 'revenue' => 1200.5, 'amount_paid' => 9],
            ],
        ]);
        $this->assertStringNotContainsString('revenue', json_encode($clean));
        $this->assertStringNotContainsString('amount', json_encode($clean));
        $this->assertSame(5, $clean['data']['totals']['orders']);
        $this->assertSame(32.5, $clean['data']['cities'][0]['lat']);
    }

    private function warehouseEmployee(?string $location): User
    {
        // The test DB's users.role CHECK predates 'employee' (prod MySQL allows it).
        \Illuminate\Support\Facades\DB::statement('PRAGMA ignore_check_constraints = ON');

        return User::factory()->createQuietly(['role' => 'employee', 'team' => 'warehouse', 'warehouse_location' => $location]);
    }

    public function test_each_warehouse_sees_only_its_own_scans_and_admin_sees_both(): void
    {
        $sd = $this->warehouseEmployee(null);        // null = San Diego
        $tj = $this->warehouseEmployee('tijuana');
        $admin = $this->user('admin');
        $this->upload($sd, 'employee', [['tracking_number' => 'SD1', 'recipient_name' => 'Uno']]);
        $this->upload($sd, 'employee', [['tracking_number' => 'SD2', 'recipient_name' => 'Dos']]);
        $this->upload($tj, 'employee', [['tracking_number' => 'SD1', 'recipient_name' => 'Uno']]); // same package, now in Tijuana

        $this->assertSame(['san_diego', 'san_diego', 'tijuana'], LabelScan::orderBy('id')->pluck('location')->all());

        // each employee: their own warehouse only — list and counts
        $this->actingAs($sd)->getJson('/employee/label-scans')->assertOk()->assertJsonPath('data.total', 2);
        $this->actingAs($tj)->getJson('/employee/label-scans')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.location', 'tijuana');
        // an employee can't widen it with ?location=
        $this->actingAs($tj)->getJson('/employee/label-scans?location=san_diego')->assertJsonPath('data.total', 1);
        $day = now('America/Los_Angeles')->toDateString();
        $this->actingAs($tj)->getJson("/employee/label-scans/stats?day={$day}")->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.location', 'tijuana');

        // admin: the whole operation, or one warehouse
        $this->actingAs($admin)->getJson('/admin/label-scans')->assertJsonPath('data.total', 3);
        $this->actingAs($admin)->getJson('/admin/label-scans?location=tijuana')->assertJsonPath('data.total', 1);
        $this->actingAs($admin)->getJson("/admin/label-scans/stats?day={$day}&location=san_diego")->assertJsonPath('data.total', 2);

        // nobody edits the other warehouse's scans
        $sdRow = LabelScan::where('location', 'san_diego')->first();
        $this->actingAs($tj)->putJson("/employee/label-scans/{$sdRow->id}", ['needs_check' => true])->assertNotFound();
        $this->actingAs($tj)->deleteJson("/employee/label-scans/{$sdRow->id}")->assertNotFound();
        $this->actingAs($sd)->putJson("/employee/label-scans/{$sdRow->id}", ['needs_check' => true])->assertOk();
    }

    public function test_update_fixes_a_row_and_destroy_removes_it(): void
    {
        $admin = $this->user('admin');
        $this->upload($admin, 'admin', [['tracking_number' => null, 'recipient_name' => 'BOXLY MONSERAT MARTINEZ', 'needs_check' => true]]);
        $scan = LabelScan::first();

        $this->actingAs($admin)->putJson("/admin/label-scans/{$scan->id}", [
            'recipient_name' => 'BOXLY MONSERRAT MARTINEZ', 'tracking_number' => '1Z22FW26YN93529953', 'needs_check' => false,
        ])->assertOk()->assertJsonPath('data.recipient_name', 'BOXLY MONSERRAT MARTINEZ')->assertJsonPath('data.needs_check', false);

        $this->actingAs($admin)->deleteJson("/admin/label-scans/{$scan->id}")->assertOk();
        $this->assertSame(0, LabelScan::count());
    }

    public function test_employee_route_works_customers_cannot(): void
    {
        $this->getJson('/admin/label-scans')->assertStatus(401); // before any actingAs: it sticks for the rest of the test
        // The users-table CHECK the test migrations build predates the 'employee' role (prod widens it with a
        // MySQL-only ENUM rewrite), so /employee is exercised with an admin, whom canManageWarehouse() also lets in.
        $this->upload($this->user('admin'), 'employee', [['tracking_number' => 'BTS_054002QMMA7', 'recipient_name' => 'Velonie Villalobos']])->assertStatus(201);
        $this->upload($this->user('customer'), 'admin', [['recipient_name' => 'x']])->assertStatus(403);
        $this->upload($this->user('customer'), 'employee', [['recipient_name' => 'x']])->assertStatus(403);
    }

    public function test_store_requires_an_image_and_at_least_one_package(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->postJson('/admin/label-scans', ['packages' => json_encode([['recipient_name' => 'x']])])->assertStatus(422);
        $this->upload($admin, 'admin', [])->assertStatus(422);
    }

    public function test_an_admin_api_key_reads_and_corrects_label_scans(): void
    {
        // Alex 2026-10-07: an admin's agent works from these with its API key (Sanctum bearer token).
        $admin = $this->user('admin');
        $this->upload($admin, 'admin', [['tracking_number' => '1Z07F8A70396079847', 'recipient_name' => 'BOXLY VASCO BAUTISTA']]);
        $this->app['auth']->forgetGuards(); // drop the session login: the key alone must work
        $key = $admin->createToken('agent', ['*'])->plainTextToken;

        $this->withToken($key)->getJson('/admin/label-scans?search=1Z07F8A7')->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.recipient_name', 'BOXLY VASCO BAUTISTA')
            ->assertJsonPath('data.data.0.tracking_number', '1Z07F8A70396079847');
        $id = LabelScan::first()->id;
        $this->withToken($key)->putJson("/admin/label-scans/{$id}", ['needs_check' => true])->assertOk()->assertJsonPath('data.needs_check', true);

        $this->app['auth']->forgetGuards();
        $customerKey = $this->user('customer')->createToken('agent', ['*'])->plainTextToken;
        $this->withToken($customerKey)->getJson('/admin/label-scans')->assertStatus(403);
    }

    public function test_since_returns_only_newer_rows(): void
    {
        $admin = $this->user('admin');
        $this->upload($admin, 'admin', [['recipient_name' => 'Old']]);
        LabelScan::query()->update(['created_at' => now()->subHour()]);
        $this->upload($admin, 'admin', [['recipient_name' => 'New']]);

        $this->actingAs($admin)->getJson('/admin/label-scans?since=' . urlencode(now()->subMinutes(5)->toIso8601String()))
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.recipient_name', 'New');
    }
}
