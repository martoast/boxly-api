<?php

namespace Tests\Feature;

use App\Models\LabelScan;
use App\Models\User;
use App\Services\LabelScanMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\LiveShoppingTestCase;

/**
 * Label scans matched to customers + /label-scans/arrivals (the 5 PM arrival WhatsApp, 2026-11-25).
 *
 *   vendor/bin/phpunit tests/Feature/LabelScanArrivalsTest.php
 */
class LabelScanArrivalsTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_04_29_000007_add_team_to_users.php', '2026_10_07_000000_create_label_scans_table.php', '2026_04_30_000000_create_personal_access_tokens_table.php', '2026_10_09_000000_add_warehouse_locations.php', '2026_11_25_000000_add_customer_match_to_label_scans.php'] as $m) {
            $this->artisan('migrate', ['--path' => "database/migrations/{$m}", '--force' => true]);
        }
        Storage::fake('spaces');
    }

    private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/wAALCAAIAAgBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==';

    private function customer(string $name, ?string $phone = '+525512345678'): User
    {
        return User::factory()->createQuietly(['role' => 'customer', 'name' => $name, 'phone' => $phone]);
    }

    private function mau(): User
    {
        // the test DB's role column predates 'employee' (as in LabelScanTest): an admin scanning for San Diego
        return User::factory()->createQuietly(['role' => 'admin']);
    }

    private function upload(User $u, array $packages)
    {
        return $this->actingAs($u)->post('/admin/label-scans', [
            'location' => 'san_diego',
            'image'    => UploadedFile::fake()->createWithContent('label.jpg', base64_decode(self::JPEG)),
            'batch'    => 'b1',
            'packages' => json_encode($packages),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_matcher_takes_one_confident_customer_or_nothing(): void
    {
        $c = fn ($id, $name, $phone = '+525500000000') => (object) ['id' => $id, 'name' => $name, 'phone' => $phone];
        $m = new LabelScanMatcher([
            $c(1, 'Adelina Oropeza Tapia'), $c(2, 'Gustavo Millan Gonzalez', '+525511111111'), $c(3, 'Gustavo Gonzalez', '+525522222222'),
            $c(4, 'Victor Hugo Reyes Torres'), $c(5, 'Fernando Antonio Guevara Garcia', '+525533333333'), $c(6, 'José Fernando García Menéndez', '+525544444444'),
            $c(7, 'jesus velazquez', '+52 55 5555 5555'), $c(8, 'Jesus Alejandro virgen velazquez', '525555555555'),
            $c(9, 'Pedro Reyes'), $c(14, 'Hugo Sanchez'), $c(15, 'Victor Mendez'), $c(10, 'Ana Torres'), $c(11, 'Luis Gonzalez'), $c(12, 'Maria Garcia'), $c(13, 'Carlos Menendez'),
        ], []);
        $this->assertSame(['status' => 'matched', 'user_id' => 1], $m->match('BOXLY ADELINA OROPEZA'), 'every label word in one customer');
        $this->assertSame(['status' => 'matched', 'user_id' => 2], $m->match('FMG Gustavo Millán González'), 'the one with exactly that name');
        $this->assertSame('none', $m->match('FMG GUSTAVO MILLA N GONZALEZ')['status'], 'a misread name names nobody — never the other Gustavo');
        $this->assertSame('none', $m->match('BOXLY VICTOR HUGO')['status'], 'first names alone are not confident');
        $this->assertSame('ambiguous', $m->match('Boxly Fernando Garcia')['status'], 'two different people');
        $this->assertSame(['status' => 'matched', 'user_id' => 8], $m->match('BOXLY JESUS VIRGEN VELAZQUEZ'), 'one person (one phone), two accounts');
        $this->assertSame('staff', $m->match('VELONIE VILLALOBOS')['status']);
        $this->assertSame('none', $m->match(null)['status']);
    }

    public function test_an_upload_is_matched_and_arrivals_group_by_customer(): void
    {
        $ana = $this->customer('Ana Lucia Perez Soto');
        $beto = $this->customer('Roberto Gomez Bolanos', '55 1234');   // not a WhatsApp number
        $this->customer('Carla Ruiz'); $this->customer('Diego Soto Perez'); $this->customer('Elena Bolanos Gomez'); // surnames are surnames
        $mau = $this->mau();
        $this->upload($mau, [['tracking_number' => '1Z1', 'carrier' => 'UPS', 'recipient_name' => 'BOXLY ANA PEREZ']])->assertStatus(201)
            ->assertJsonPath('data.0.user_id', $ana->id)->assertJsonPath('data.0.match_status', 'matched');
        $this->upload($mau, [['tracking_number' => '9400A', 'recipient_name' => 'Boxly Ana Lucia Perez'], ['tracking_number' => 'TBA2', 'recipient_name' => 'VELONIE VILLALOBOS']])->assertStatus(201);
        $this->upload($mau, [['tracking_number' => 'X3', 'recipient_name' => 'Roberto Gomez']]);
        $this->upload($mau, [['tracking_number' => 'Q4', 'recipient_name' => 'Ana Perez', 'needs_check' => true]]);
        $this->upload($mau, [['tracking_number' => 'N5', 'recipient_name' => 'BOXLY Nadie Conocido']]);

        $admin = User::factory()->createQuietly(['role' => 'admin']);
        $r = $this->actingAs($admin)->getJson('/label-scans/arrivals?since=2000-01-01T00:00:00Z')->assertOk();
        $customers = $r->json('data.customers');
        $this->assertCount(1, $customers, 'only Ana: Roberto has no WhatsApp number');
        $this->assertSame($ana->id, $customers[0]['user_id']);
        $this->assertSame('+525512345678', $customers[0]['phone']);
        $this->assertSame(['1Z1', '9400A'], array_column($customers[0]['scans'], 'tracking_number'));
        $this->assertSame([false, true], array_column($customers[0]['scans'], 'image_shared'), 'the photo with two labels is shared');
        $unmatched = collect($r->json('data.unmatched'))->keyBy('tracking_number');
        $this->assertSame('staff', $unmatched['TBA2']['match_status']);
        $this->assertSame('no_phone', $unmatched['X3']['match_status']);
        $this->assertSame('none', $unmatched['N5']['match_status']);
        $this->assertSame(['Q4'], array_column($r->json('data.needs_check'), 'tracking_number'), 'held until a person clears it');
        $this->assertSame('san_diego', $r->json('data.window.location'));
    }

    public function test_a_person_assigns_the_customer_and_a_corrected_name_is_matched_again(): void
    {
        $ana = $this->customer('Ana Lucia Perez Soto');
        $this->customer('Carla Ruiz Soto');
        $mau = $this->mau();
        $this->upload($mau, [['tracking_number' => 'A1', 'recipient_name' => 'Boxly Anna Perz']]);
        $row = LabelScan::where('tracking_number', 'A1')->first();
        $this->assertSame('none', $row->match_status);
        $this->actingAs($mau)->putJson("/admin/label-scans/{$row->id}", ['recipient_name' => 'Boxly Ana Perez'])->assertOk()
            ->assertJsonPath('data.user_id', $ana->id)->assertJsonPath('data.match_status', 'matched');
        $this->actingAs($mau)->putJson("/admin/label-scans/{$row->id}", ['user_id' => null])->assertOk()
            ->assertJsonPath('data.user_id', null)->assertJsonPath('data.match_status', 'manual');
        $this->actingAs($mau)->putJson("/admin/label-scans/{$row->id}", ['recipient_name' => 'Ana Perez Soto'])->assertOk()
            ->assertJsonPath('data.match_status', 'manual');
        $this->assertSame($row->id, LabelScan::where('tracking_number', 'A1')->value('id'), 'the scan id never changes');
    }

    public function test_the_arrivals_token_reads_arrivals_and_nothing_else(): void
    {
        $admin = User::factory()->createQuietly(['role' => 'admin']);
        $limited = $admin->createToken('suite-arrivals', ['label-scans:arrivals'])->plainTextToken;
        $full = $admin->createToken('cli')->plainTextToken;
        $customer = $this->customer('Ana Perez');
        $customerToken = $customer->createToken('chat')->plainTextToken;
        $get = function ($token, $url) { $this->app['auth']->forgetGuards(); return $this->withHeader('Authorization', "Bearer {$token}")->getJson($url); };

        $get($limited, '/label-scans/arrivals?since=2000-01-01T00:00:00Z')->assertOk();
        $get($limited, '/admin/label-scans')->assertStatus(403);
        $get($limited, '/admin/dashboard/v3/overview')->assertStatus(403);
        $get($full, '/admin/label-scans')->assertOk();
        $get($full, '/label-scans/arrivals?since=2000-01-01T00:00:00Z')->assertOk();
        $get($customerToken, '/label-scans/arrivals?since=2000-01-01T00:00:00Z')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/label-scans/arrivals?since=2000-01-01T00:00:00Z')->assertStatus(401);
    }
}
