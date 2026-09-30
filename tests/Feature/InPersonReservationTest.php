<?php

namespace Tests\Feature;

use App\Mail\InPersonFinalInvoice;
use App\Mail\InPersonReservationCancelled;
use App\Mail\InPersonReservationConfirmed;
use App\Mail\InPersonReservationManagerAlert;
use App\Mail\InPersonSlotTaken;
use App\Models\ShoppingReservation;
use App\Models\ShoppingReservationSlot;
use App\Models\ShoppingSlot;
use App\Models\User;
use App\Services\InPersonReservationService;
use App\Services\InPersonStripeGateway;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\LiveShoppingTestCase;

/** Stripe seam fake: records every call, can be told to fail. */
class FakeInPersonGateway implements InPersonStripeGateway
{
    public array $created = [];
    public array $expired = [];
    public array $refunded = [];
    public array $paid = []; // session id => ['payment_intent' => , 'invoice' => ]
    public bool $failCreate = false;
    public bool $failExpire = false;
    public bool $failRefund = false;
    public bool $failInvoice = false;
    public array $invoices = []; // ['customer' =>, 'params' =>, 'lines' =>]

    public function createCheckoutSession(array $params): object
    {
        if ($this->failCreate) {
            throw new \RuntimeException('stripe down');
        }
        $this->created[] = $params;
        $id = 'cs_test_' . count($this->created);

        return (object) ['id' => $id, 'url' => "https://checkout.stripe.test/$id"];
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        $this->expired[] = $sessionId;
        if ($this->failExpire) {
            throw new \RuntimeException('cannot expire');
        }
    }

    public function retrieveSession(string $sessionId): object
    {
        $p = $this->paid[$sessionId] ?? null;

        return (object) ['id' => $sessionId, 'payment_status' => $p ? 'paid' : 'unpaid',
            'payment_intent' => $p['payment_intent'] ?? null, 'invoice' => $p['invoice'] ?? null];
    }

    public function createAndSendInvoice(string $customerId, array $invoiceParams, array $lines): object
    {
        if ($this->failInvoice) {
            throw new \RuntimeException('invoice failed');
        }
        $this->invoices[] = ['customer' => $customerId, 'params' => $invoiceParams, 'lines' => $lines];
        $id = 'in_final_' . count($this->invoices);

        return (object) ['id' => $id, 'hosted_invoice_url' => "https://invoice.stripe.test/$id"];
    }

    public function refundPaymentIntent(string $paymentIntentId): void
    {
        if ($this->failRefund) {
            throw new \RuntimeException('refund failed');
        }
        $this->refunded[] = $paymentIntentId;
    }
}

class InPersonReservationTest extends LiveShoppingTestCase
{
    private FakeInPersonGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_04_29_000007_add_team_to_users.php', '--force' => true]);
        Schema::table('users', fn (Blueprint $t) => $t->string('stripe_shopping_id')->nullable());
        Schema::create('purchase_requests', fn (Blueprint $t) => $t->id());
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_30_000000_create_in_person_reservation_tables.php', '--force' => true]);

        // The users.role enum check (customer|admin on SQLite; prod MySQL also allows employee) would refuse staff rows.
        \Illuminate\Support\Facades\DB::statement('PRAGMA ignore_check_constraints = ON');
        Mail::fake();
        config(['services.stripe_shopping.webhook_secret' => 'whsec_test', 'app.frontend_url' => 'https://app.test']);
        $this->stripe = new FakeInPersonGateway();
        $this->app->instance(InPersonStripeGateway::class, $this->stripe);
        // Thursday 2026-10-01 05:00 PDT
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(12, 0, 0));
    }

    // ---------- helpers ----------

    private function svc(): InPersonReservationService
    {
        return app(InPersonReservationService::class);
    }

    private function customer(array $o = []): User
    {
        return User::factory()->createQuietly(array_merge(['stripe_shopping_id' => 'cus_test', 'phone' => '+52 664 111 2222'], $o));
    }

    private function staff(string $role, ?string $team = null): User
    {
        return User::factory()->createQuietly(['role' => $role, 'team' => $team]);
    }

    /** Open local hours [11, 12, ...] on a local date. */
    private function open(string $date, array $hours): void
    {
        foreach ($hours as $h) {
            ShoppingSlot::create(['starts_at' => $this->svc()->utc($date, sprintf('%02d:00', $h))]);
        }
    }

    private function reserve(User $u, string $date, int $hour, int $hours = 1): ShoppingReservation
    {
        return $this->svc()->createReservation($u, $date, sprintf('%02d:00', $hour), $hours, null)[0];
    }

    private function webhook(string $type, ShoppingReservation $r, string $pi = 'pi_1', string $invoice = 'in_1')
    {
        $payload = json_encode(['id' => 'evt_' . uniqid(), 'object' => 'event', 'type' => $type, 'data' => ['object' => [
            'id' => $r->stripe_checkout_session_id, 'object' => 'checkout.session', 'payment_status' => 'paid',
            'payment_intent' => $pi, 'invoice' => $invoice,
            'metadata' => ['type' => 'in_person_reservation', 'reservation_id' => (string) $r->id],
        ]]]);
        $t = time();
        $sig = hash_hmac('sha256', "$t.$payload", 'whsec_test');

        return $this->call('POST', '/webhooks/stripe-shopping', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t=$t,v1=$sig", 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    private function pay(ShoppingReservation $r, string $pi = 'pi_1')
    {
        return $this->webhook('checkout.session.completed', $r, $pi)->assertOk();
    }

    private function as(User $u): self
    {
        return $this->actingAs($u, 'sanctum');
    }

    private function activeLocks(): int
    {
        return ShoppingReservationSlot::whereNotNull('active_slot_id')->count();
    }

    // ---------- availability ----------

    public function test_availability_hides_past_and_confirmed_but_not_pending(): void
    {
        $c = $this->customer();
        $this->open('2026-10-01', [4]);            // 04:00 PDT = past (now is 05:00)
        $this->open('2026-10-03', [11, 12, 13, 15]);
        $this->reserve($this->customer(), '2026-10-03', 12);       // pending only: blocks nobody
        $r = $this->reserve($this->customer(), '2026-10-03', 15);
        $this->pay($r);                                             // confirmed: 15:00 gone

        $res = $this->as($c)->getJson('/in-person/availability')->assertOk()->json('data');

        $this->assertCount(1, $res);
        $this->assertSame('2026-10-03', $res[0]['date']);
        $this->assertSame(['11:00', '12:00', '13:00'], array_column($res[0]['slots'], 'start_time'));
        $this->assertSame([3, 2, 1], array_column($res[0]['slots'], 'max_consecutive_hours'));
        $this->assertSame('12:00', $res[0]['slots'][0]['end_time']);
    }

    public function test_availability_caps_consecutive_hours_at_six(): void
    {
        $this->open('2026-10-03', range(8, 17));
        $first = $this->as($this->customer())->getJson('/in-person/availability')->json('data.0.slots.0');
        $this->assertSame(6, $first['max_consecutive_hours']);
    }

    public function test_availability_uses_pacific_now_around_midnight(): void
    {
        $this->open('2026-10-01', [22]);   // 22:00 PDT Oct 1
        $this->open('2026-10-02', [6]);
        // 23:30 PDT Oct 1 = 06:30 UTC Oct 2: the 22:00 hour is past, today (Pacific) is still Oct 1
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(6, 30, 0));
        $dates = array_column($this->as($this->customer())->getJson('/in-person/availability')->json('data'), 'date');
        $this->assertSame(['2026-10-02'], $dates);
        // 21:30 PDT Oct 1 = 04:30 UTC Oct 2: the 22:00 hour is still offered
        $this->travelTo(now()->setDate(2026, 10, 2)->setTime(4, 30, 0));
        $dates = array_column($this->as($this->customer())->getJson('/in-person/availability')->json('data'), 'date');
        $this->assertSame(['2026-10-01', '2026-10-02'], $dates);
    }

    public function test_availability_requires_auth(): void
    {
        $this->getJson('/in-person/availability')->assertStatus(401);
    }

    // ---------- create reservation ----------

    public function test_create_reservation_charges_only_first_hour_and_blocks_nothing(): void
    {
        $this->open('2026-10-03', [11, 12, 13]);
        $u = $this->customer();

        $res = $this->as($u)->postJson('/in-person/reservations', [
            'date' => '2026-10-03', 'start_time' => '11:00', 'hours' => 3, 'customer_notes' => 'Nike please',
        ])->assertStatus(201)->assertJsonPath('success', true);

        $this->assertStringStartsWith('https://checkout.stripe.test/', $res->json('checkout_url'));
        $this->assertSame('pending_payment', $res->json('data.status'));
        $this->assertEquals(30, $res->json('data.amount_usd'));
        $this->assertSame('11:00', $res->json('data.start_time'));
        $this->assertSame('14:00', $res->json('data.end_time'));
        $this->assertSame(3, $res->json('data.hours_reserved'));
        $this->assertMatchesRegularExpression('/^RV-2026-\d{4}$/', $res->json('data.reservation_number'));

        $p = $this->stripe->created[0];
        $this->assertSame('payment', $p['mode']);
        $this->assertSame(3000, $p['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('usd', $p['line_items'][0]['price_data']['currency']);
        $this->assertTrue($p['invoice_creation']['enabled']);
        $this->assertSame('in_person_reservation', $p['metadata']['type']);
        $this->assertSame('https://app.test/in-person/success?ref=' . $res->json('data.reservation_number') . '&session_id={CHECKOUT_SESSION_ID}', $p['success_url']);
        $this->assertSame('https://app.test/in-person?cancelled=1', $p['cancel_url']);
        $this->assertGreaterThan(now()->addMinutes(29)->timestamp, $p['expires_at']);
        $this->assertLessThan(now()->addMinutes(35)->timestamp, $p['expires_at']);

        $this->assertSame(0, $this->activeLocks());
        $this->assertCount(3, $this->as($u)->getJson('/in-person/availability')->json('data.0.slots'));
    }

    public function test_create_rejects_already_paid_missing_and_past_hours(): void
    {
        $this->open('2026-10-03', [11, 12]);
        $this->open('2026-10-01', [4]);
        $this->pay($this->reserve($this->customer(), '2026-10-03', 12));
        $u = $this->customer();
        $post = fn ($d, $t, $h) => $this->as($u)->postJson('/in-person/reservations', ['date' => $d, 'start_time' => $t, 'hours' => $h]);

        $post('2026-10-03', '12:00', 1)->assertStatus(422)->assertJson(['success' => false, 'message' => 'Ese horario ya fue reservado']);
        $post('2026-10-03', '11:00', 2)->assertStatus(422)->assertJsonPath('message', 'Ese horario ya fue reservado'); // 12:00 is taken
        $post('2026-10-03', '13:00', 1)->assertStatus(422);  // never published
        $post('2026-10-01', '04:00', 1)->assertStatus(422);  // past
        $post('2026-10-03', '11:30', 1)->assertStatus(422);  // off the hour
        $this->assertSame(1, ShoppingReservation::count());
    }

    public function test_hours_bounds(): void
    {
        $this->open('2026-10-03', range(8, 16));
        $u = $this->customer();
        $post = fn ($h) => $this->as($u)->postJson('/in-person/reservations', ['date' => '2026-10-03', 'start_time' => '08:00', 'hours' => $h]);
        $post(0)->assertStatus(422);
        $post(7)->assertStatus(422);
        $post(6)->assertStatus(201)->assertJsonPath('data.end_time', '14:00');
    }

    public function test_stripe_failure_returns_502_and_deletes_the_pending_row(): void
    {
        $this->open('2026-10-03', [11]);
        $this->stripe->failCreate = true;
        $this->as($this->customer())->postJson('/in-person/reservations', ['date' => '2026-10-03', 'start_time' => '11:00', 'hours' => 1])
            ->assertStatus(502)->assertJsonPath('success', false);
        $this->assertSame(0, ShoppingReservation::count());
    }

    // ---------- first confirmed payment wins ----------

    public function test_first_paid_wins_second_becomes_slot_taken_refunded_once_and_apologised(): void
    {
        $this->open('2026-10-03', [11]);
        [$a, $b] = [$this->customer(), $this->customer()];
        $ra = $this->reserve($a, '2026-10-03', 11);
        $rb = $this->reserve($b, '2026-10-03', 11);   // both on the Stripe page: allowed

        $this->pay($ra, 'pi_a');
        $this->pay($rb, 'pi_b');

        $this->assertSame('confirmed', $ra->fresh()->status);
        $this->assertSame('slot_taken', $rb->fresh()->status);
        $this->assertSame(['pi_b'], $this->stripe->refunded);
        $this->assertNotNull($rb->fresh()->refunded_at);
        $this->assertSame(1, $this->activeLocks());
        Mail::assertQueued(InPersonSlotTaken::class, 1);
        Mail::assertQueued(InPersonSlotTaken::class, fn ($m) => $m->hasTo($b->email));

        $this->pay($rb, 'pi_b');   // replay of the loser: still one refund, one apology
        $this->assertCount(1, $this->stripe->refunded);
        Mail::assertQueued(InPersonSlotTaken::class, 1);
    }

    public function test_overlapping_multi_hour_requests_exactly_one_wins(): void
    {
        $this->open('2026-10-03', [10, 11, 12, 13]);
        $r2 = $this->reserve($this->customer(), '2026-10-03', 10, 2);   // 10-12
        $r3 = $this->reserve($this->customer(), '2026-10-03', 11, 3);   // 11-14, shares 11:00
        $this->pay($r3, 'pi_3');
        $this->pay($r2, 'pi_2');

        $this->assertSame('confirmed', $r3->fresh()->status);
        $this->assertSame('slot_taken', $r2->fresh()->status);
        $this->assertSame(3, $this->activeLocks());    // the loser left no partial rows
        $this->assertSame(['pi_2'], $this->stripe->refunded);
        $this->assertSame(0, ShoppingReservationSlot::where('reservation_id', $r2->id)->count());

        $slot = ShoppingSlot::first();
        $this->expectException(QueryException::class);  // the unique index itself refuses a second active row
        ShoppingReservationSlot::create(['reservation_id' => $r2->id, 'shopping_slot_id' => $slot->id, 'active_slot_id' => ShoppingReservationSlot::first()->active_slot_id]);
    }

    public function test_multi_hour_locks_every_hour_and_charges_thirty_only(): void
    {
        $this->open('2026-10-03', [10, 11, 12]);
        $r = $this->reserve($this->customer(), '2026-10-03', 10, 3);
        $this->pay($r);
        $this->assertSame(3, $this->activeLocks());
        $this->assertEquals(30, $r->fresh()->amount_usd);
        $this->assertSame([], $this->as($this->customer())->getJson('/in-person/availability')->json('data'));
    }

    public function test_replay_is_idempotent_and_mails_go_to_the_right_people(): void
    {
        $this->open('2026-10-03', [11]);
        $velonie = $this->staff('employee', 'shopping');
        $second = $this->staff('employee', 'shopping');
        $warehouse = $this->staff('employee', 'warehouse');
        $admin = $this->staff('admin');
        $c = $this->customer(['preferred_language' => 'en']);
        $r = $this->reserve($c, '2026-10-03', 11);

        $this->pay($r);
        $this->pay($r);
        $this->pay($r);

        $fresh = $r->fresh();
        $this->assertSame('confirmed', $fresh->status);
        $this->assertSame('pi_1', $fresh->stripe_payment_intent_id);
        $this->assertSame('in_1', $fresh->stripe_invoice_id);
        $this->assertNotNull($fresh->paid_at);
        $this->assertNotNull($fresh->confirmation_sent_at);
        $this->assertSame(1, $this->activeLocks());
        Mail::assertQueued(InPersonReservationConfirmed::class, 1);
        Mail::assertQueued(InPersonReservationConfirmed::class, fn ($m) => $m->hasTo($c->email));
        Mail::assertQueued(InPersonReservationManagerAlert::class, 2);
        Mail::assertQueued(InPersonReservationManagerAlert::class, fn ($m) => $m->hasTo($velonie->email) && ! $m->hasTo($second->email));
        Mail::assertQueued(InPersonReservationManagerAlert::class, fn ($m) => $m->hasTo($second->email));
        Mail::assertNotQueued(InPersonReservationManagerAlert::class, fn ($m) => $m->hasTo($admin->email) || $m->hasTo($warehouse->email) || $m->hasTo($c->email));
        Mail::assertNotQueued(InPersonSlotTaken::class);
    }

    public function test_mails_render_es_and_en(): void
    {
        $this->open('2026-10-03', [11]);
        $this->staff('employee', 'shopping');
        foreach (['es', 'en'] as $lang) {
            $r = $this->reserve($this->customer(['preferred_language' => $lang, 'name' => 'Ana <b>']), '2026-10-03', 11);
            $r->refresh()->load('user');
            foreach ([InPersonReservationConfirmed::class, InPersonSlotTaken::class, InPersonReservationCancelled::class, InPersonReservationManagerAlert::class] as $m) {
                $html = (new $m($r))->render();
                $this->assertStringContainsString('hora de California' === '' ? '' : $r->reservation_number, $html);
                $this->assertStringNotContainsString('Ana <b>', $html);
            }
        }
    }

    public function test_paying_winner_expires_overlapping_sessions_and_failure_is_ignored(): void
    {
        $this->open('2026-10-03', [10, 11, 12, 13]);
        $other = $this->reserve($this->customer(), '2026-10-03', 12, 2);   // 12-14
        $overlap = $this->reserve($this->customer(), '2026-10-03', 11, 2); // 11-13 overlaps winner and other
        $apart = $this->reserve($this->customer(), '2026-10-03', 13, 1);   // 13-14 does not overlap 10-12
        $winner = $this->reserve($this->customer(), '2026-10-03', 10, 2);  // 10-12
        $this->stripe->failExpire = true;

        $this->pay($winner);

        $this->assertSame('confirmed', $winner->fresh()->status);   // failure ignored
        $this->assertEqualsCanonicalizing([$overlap->stripe_checkout_session_id], $this->stripe->expired);
        $this->assertNotContains($apart->stripe_checkout_session_id, $this->stripe->expired);
        $this->assertNotContains($other->stripe_checkout_session_id, $this->stripe->expired);
    }

    public function test_refund_failure_is_logged_and_flagged(): void
    {
        Log::spy();
        $this->open('2026-10-03', [11]);
        $ra = $this->reserve($this->customer(), '2026-10-03', 11);
        $rb = $this->reserve($this->customer(), '2026-10-03', 11);
        $this->pay($ra, 'pi_a');
        $this->stripe->failRefund = true;
        $this->pay($rb, 'pi_b');

        $rb = $rb->fresh();
        $this->assertSame('slot_taken', $rb->status);
        $this->assertNull($rb->refunded_at);
        $this->assertFalse($this->as($rb->user)->getJson('/in-person/reservations/' . $rb->reservation_number)->json('data.refunded'));
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'refund FAILED'))->once();
        Mail::assertQueued(InPersonSlotTaken::class, 1);
    }

    public function test_expired_webhook_marks_pending_expired_only(): void
    {
        $this->open('2026-10-03', [11, 12]);
        $p = $this->reserve($this->customer(), '2026-10-03', 11);
        $c = $this->reserve($this->customer(), '2026-10-03', 12);
        $this->pay($c);
        $this->webhook('checkout.session.expired', $p)->assertOk();
        $this->webhook('checkout.session.expired', $c)->assertOk();
        $this->assertSame('expired', $p->fresh()->status);
        $this->assertSame('confirmed', $c->fresh()->status);
    }

    // ---------- success endpoint ----------

    public function test_success_endpoint_confirms_a_paid_but_unprocessed_session(): void
    {
        $this->open('2026-10-03', [11]);
        $u = $this->customer();
        $r = $this->reserve($u, '2026-10-03', 11);

        $this->as($u)->getJson('/in-person/reservations/' . $r->reservation_number)->assertOk()->assertJsonPath('data.status', 'pending_payment');

        $this->stripe->paid[$r->stripe_checkout_session_id] = ['payment_intent' => 'pi_late', 'invoice' => 'in_late'];
        $res = $this->as($u)->getJson('/in-person/reservations/' . $r->reservation_number)->assertOk();
        $this->assertSame('confirmed', $res->json('data.status'));
        $this->assertSame('11:00', $res->json('data.start_time'));
        $this->assertSame('12:00', $res->json('data.end_time'));
        $this->assertSame('Las Americas Premium Outlets', $res->json('data.location'));
        $this->assertSame('16194937969', $res->json('data.whatsapp'));
        $this->assertFalse($res->json('data.refunded'));
        $this->assertSame(1, $this->activeLocks());
        $this->as($u)->getJson('/in-person/reservations/' . $r->reservation_number);   // again: no double mail
        Mail::assertQueued(InPersonReservationConfirmed::class, 1);
    }

    public function test_reservation_is_owner_only(): void
    {
        $this->open('2026-10-03', [11]);
        $r = $this->reserve($this->customer(), '2026-10-03', 11);
        $this->as($this->customer())->getJson('/in-person/reservations/' . $r->reservation_number)->assertStatus(404);
    }

    // ---------- team routes ----------

    public function test_team_routes_roles(): void
    {
        foreach (['/shopping', '/admin'] as $prefix) {
            foreach ([$this->customer(), $this->staff('employee', 'warehouse')] as $denied) {
                $this->as($denied)->getJson("$prefix/in-person/slots")->assertStatus(403);
            }
        }
        $this->as($this->staff('employee', 'shopping'))->getJson('/shopping/in-person/slots')->assertOk();
        $this->as($this->staff('admin'))->getJson('/shopping/in-person/slots')->assertOk();
        $this->as($this->staff('admin'))->getJson('/admin/in-person/slots')->assertOk();
        $this->as($this->staff('employee', 'shopping'))->getJson('/admin/in-person/slots')->assertStatus(403);
    }

    public function test_put_slots_adds_removes_and_lists_with_reservation(): void
    {
        $t = $this->staff('employee', 'shopping');
        $res = $this->as($t)->putJson('/shopping/in-person/slots', ['add' => [
            ['date' => '2026-10-03', 'start_time' => '11:00'], ['date' => '2026-10-03', 'start_time' => '12:00'],
        ]])->assertOk()->assertJsonPath('data.added', 2);
        $this->as($t)->putJson('/shopping/in-person/slots', ['add' => [['date' => '2026-10-03', 'start_time' => '11:00']]])
            ->assertJsonPath('data.added', 0);

        $u = $this->customer(['name' => 'Ana']);
        $r = $this->reserve($u, '2026-10-03', 11);
        $this->pay($r);

        $slots = $this->as($t)->getJson('/shopping/in-person/slots?from=2026-10-03&to=2026-10-03')->assertOk()->json('data.slots');
        $this->assertSame('booked', $slots[0]['status']);
        $this->assertSame('open', $slots[1]['status']);
        $this->assertNull($slots[1]['reservation']);
        $this->assertSame('Ana', $slots[0]['reservation']['customer']['name']);
        $this->assertSame('pi_1', $slots[0]['reservation']['stripe_payment_intent_id']);
        $this->assertSame('11:00', $slots[0]['start_time']);
        $this->assertSame('12:00', $slots[0]['end_time']);

        $this->as($t)->putJson('/shopping/in-person/slots', ['remove' => [['date' => '2026-10-03', 'start_time' => '12:00']]])
            ->assertJsonPath('data.removed', 1);
    }

    public function test_put_slots_validation(): void
    {
        $t = $this->staff('employee', 'shopping');
        foreach (['05:00', '23:00', '11:30'] as $bad) {
            $this->as($t)->putJson('/shopping/in-person/slots', ['add' => [['date' => '2026-10-03', 'start_time' => $bad]]])->assertStatus(422);
        }
        $this->as($t)->putJson('/shopping/in-person/slots', ['add' => [['date' => '2026-09-30', 'start_time' => '11:00']]])->assertStatus(422);
    }

    public function test_slot_removal_refused_when_locked_and_allowed_after_cancel(): void
    {
        $t = $this->staff('employee', 'shopping');
        $this->open('2026-10-03', [11, 12, 13]);
        $r = $this->reserve($this->customer(), '2026-10-03', 12, 2);
        $this->pay($r);

        $res = $this->as($t)->putJson('/shopping/in-person/slots', [
            'add' => [['date' => '2026-10-04', 'start_time' => '09:00']],
            'remove' => [['date' => '2026-10-03', 'start_time' => '11:00'], ['date' => '2026-10-03', 'start_time' => '13:00']],
        ])->assertStatus(422);
        $this->assertSame([['date' => '2026-10-03', 'start_time' => '13:00', 'reservation_number' => $r->reservation_number]], $res->json('booked'));
        $this->assertSame(3, ShoppingSlot::count());   // nothing applied, not even the add

        $this->as($t)->postJson("/shopping/in-person/reservations/{$r->id}/cancel", ['reason' => 'x'])->assertOk();
        $this->as($t)->putJson('/shopping/in-person/slots', ['remove' => [['date' => '2026-10-03', 'start_time' => '13:00']]])
            ->assertOk()->assertJsonPath('data.removed', 1);   // history rows do not block the delete
    }

    public function test_cancel_frees_hours_and_mails_customer(): void
    {
        $t = $this->staff('employee', 'shopping');
        $this->open('2026-10-03', [11, 12]);
        $u = $this->customer();
        $r = $this->reserve($u, '2026-10-03', 11, 2);
        $this->pay($r, 'pi_c');

        $this->as($t)->postJson("/shopping/in-person/reservations/{$r->id}/cancel", [])->assertStatus(422);
        $res = $this->as($t)->postJson("/shopping/in-person/reservations/{$r->id}/cancel", ['reason' => 'Mall closed'])->assertOk();
        $this->assertSame('cancelled', $res->json('data.status'));
        $this->assertSame('pi_c', $res->json('data.stripe_payment_intent_id'));
        $this->assertSame(0, $this->activeLocks());
        $this->assertCount(2, $this->as($u)->getJson('/in-person/availability')->json('data.0.slots'));
        Mail::assertQueued(InPersonReservationCancelled::class, fn ($m) => $m->hasTo($u->email));
        $this->as($t)->postJson("/shopping/in-person/reservations/{$r->id}/cancel", ['reason' => 'again'])->assertStatus(422);
        Mail::assertQueued(InPersonReservationCancelled::class, 1);
        $this->assertSame([], $this->stripe->refunded);   // refunds are manual

        $r2 = $this->reserve($this->customer(), '2026-10-03', 11, 2);   // the hours can be won again
        $this->pay($r2, 'pi_d');
        $this->assertSame('confirmed', $r2->fresh()->status);
    }

    public function test_complete_records_hours_and_spend(): void
    {
        $t = $this->staff('admin');
        $this->open('2026-10-03', [11]);
        $r = $this->reserve($this->customer(), '2026-10-03', 11);
        $this->as($t)->postJson("/admin/in-person/reservations/{$r->id}/complete", ['hours_worked' => 1.5, 'amount_spent_usd' => 240.5])->assertStatus(422);
        $this->pay($r);
        $res = $this->as($t)->postJson("/admin/in-person/reservations/{$r->id}/complete", ['hours_worked' => 1.5, 'amount_spent_usd' => 240.5])->assertOk();
        $this->assertSame('completed', $res->json('data.status'));
        $this->assertSame(1.5, $res->json('data.hours_worked'));
        $this->assertSame(240.5, $res->json('data.amount_spent_usd'));
        $this->assertSame(0, $this->activeLocks());
    }

    public function test_reservations_list_filters(): void
    {
        $t = $this->staff('employee', 'shopping');
        $this->open('2026-10-03', [11, 12]);
        $this->pay($this->reserve($this->customer(), '2026-10-03', 11));
        $this->reserve($this->customer(), '2026-10-03', 12);
        $all = $this->as($t)->getJson('/shopping/in-person/reservations?from=2026-10-03&to=2026-10-03')->assertOk()->json('data');
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('customer', $all[0]);
        $this->assertCount(1, $this->as($t)->getJson('/shopping/in-person/reservations?from=2026-10-03&to=2026-10-03&status=confirmed')->json('data'));
        $this->assertCount(0, $this->as($t)->getJson('/shopping/in-person/reservations?from=2026-10-04&to=2026-10-05')->json('data'));
    }

    // ---------- copy-week ----------

    public function test_copy_week(): void
    {
        $t = $this->staff('employee', 'shopping');
        $this->open('2026-10-05', [10, 11]);   // Monday
        $this->open('2026-10-10', [14]);       // Saturday
        $res = $this->as($t)->postJson('/shopping/in-person/slots/copy-week', [
            'from_week_start' => '2026-10-05', 'weeks' => ['2026-10-12', '2026-10-19', '2026-10-05'],
        ])->assertOk();
        $this->assertSame(6, $res->json('data.copied'));
        $this->assertSame([['week_start' => '2026-10-05', 'reason' => 'same_week']], $res->json('data.skipped'));
        $this->assertSame(9, ShoppingSlot::count());

        // running it again never duplicates or removes
        $again = $this->as($t)->postJson('/shopping/in-person/slots/copy-week', ['from_week_start' => '2026-10-05', 'weeks' => ['2026-10-12']])->json('data');
        $this->assertSame(0, $again['copied']);
        $this->assertSame(9, ShoppingSlot::count());

        // not a Monday
        $this->as($t)->postJson('/shopping/in-person/slots/copy-week', ['from_week_start' => '2026-10-06', 'weeks' => ['2026-10-12']])->assertStatus(422);
        // past week skipped, empty source skipped
        $past = $this->as($t)->postJson('/shopping/in-person/slots/copy-week', ['from_week_start' => '2026-10-05', 'weeks' => ['2026-09-21']])->json('data');
        $this->assertSame([['week_start' => '2026-09-21', 'reason' => 'past']], $past['skipped']);
        $empty = $this->as($t)->postJson('/shopping/in-person/slots/copy-week', ['from_week_start' => '2026-11-16', 'weeks' => ['2026-11-23']])->json('data');
        $this->assertSame('source_week_empty', $empty['skipped'][0]['reason']);
    }

    public function test_copy_week_skips_past_days_of_the_current_week(): void
    {
        $t = $this->staff('employee', 'shopping');
        $this->open('2026-09-21', [10]);   // previous-week Monday, now Thu Oct 1
        $this->open('2026-09-28', [10]);   // this week's Monday (past)
        $this->open('2026-09-30', [10]);   // Wednesday (past)
        $this->open('2026-10-02', [10]);   // Friday (future)
        // copy last week's (Sep 21) and this week's (Sep 28) into the week of Sep 28 / Oct 5
        $res = $this->as($t)->postJson('/shopping/in-person/slots/copy-week', ['from_week_start' => '2026-09-21', 'weeks' => ['2026-09-28']])->json('data');
        $this->assertSame(0, $res['copied']);  // Mon Sep 28 is in the past and already exists
        $res = $this->as($t)->postJson('/shopping/in-person/slots/copy-week', ['from_week_start' => '2026-09-28', 'weeks' => ['2026-10-05']])->json('data');
        $this->assertSame(3, $res['copied']);
    }

    // ---------- DST ----------

    public function test_dst_local_hours_round_trip_with_different_utc_offsets(): void
    {
        $sat = $this->svc()->utc('2026-10-31', '10:00');   // PDT, UTC-7
        $sun = $this->svc()->utc('2026-11-01', '10:00');   // PST, UTC-8 (DST ended at 02:00)
        $this->assertSame('2026-10-31 17:00:00', $sat->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 18:00:00', $sun->format('Y-m-d H:i:s'));

        $a = ShoppingSlot::create(['starts_at' => $sat]);
        $b = ShoppingSlot::create(['starts_at' => $sun]);
        $this->assertSame('2026-10-31 10:00', $a->fresh()->local()->format('Y-m-d H:i'));
        $this->assertSame('2026-11-01 10:00', $b->fresh()->local()->format('Y-m-d H:i'));
        $this->assertSame(-7 * 3600, $a->fresh()->local()->utcOffset() * 60);
        $this->assertSame(-8 * 3600, $b->fresh()->local()->utcOffset() * 60);
    }

    public function test_availability_across_the_dst_change_lists_each_local_hour_once(): void
    {
        $t = $this->staff('employee', 'shopping');
        $hours = [6, 7, 8, 9, 10, 11];
        $this->as($t)->putJson('/shopping/in-person/slots', ['add' => array_merge(
            array_map(fn ($h) => ['date' => '2026-10-31', 'start_time' => sprintf('%02d:00', $h)], $hours),
            array_map(fn ($h) => ['date' => '2026-11-01', 'start_time' => sprintf('%02d:00', $h)], $hours),
            array_map(fn ($h) => ['date' => '2026-11-02', 'start_time' => sprintf('%02d:00', $h)], $hours),
        )])->assertOk()->assertJsonPath('data.added', 18);

        $data = $this->as($this->customer())->getJson('/in-person/availability?from=2026-10-26&to=2026-11-08')->assertOk()->json('data');
        $this->assertSame(['2026-10-31', '2026-11-01', '2026-11-02'], array_column($data, 'date'));
        foreach ($data as $day) {
            $this->assertSame(['06:00', '07:00', '08:00', '09:00', '10:00', '11:00'], array_column($day['slots'], 'start_time'));
            $this->assertCount(6, array_unique(array_column($day['slots'], 'id')));
            $this->assertSame(6, $day['slots'][0]['max_consecutive_hours']);
        }
        $this->assertSame(18, count(array_unique(array_merge(...array_map(fn ($d) => array_column($d['slots'], 'id'), $data)))));

        // a 3 h reservation on the DST day is stored with the right UTC instant and reads back local
        $r = $this->as($this->customer())->postJson('/in-person/reservations', ['date' => '2026-11-01', 'start_time' => '10:00', 'hours' => 2])->assertStatus(201);
        $this->assertSame('10:00', $r->json('data.start_time'));
        $this->assertSame('12:00', $r->json('data.end_time'));
        $this->assertSame('2026-11-01 18:00:00', ShoppingReservation::first()->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_copy_week_across_the_dst_change_keeps_local_hours(): void
    {
        $t = $this->staff('employee', 'shopping');
        $this->open('2026-10-26', [10]);   // Monday, PDT
        $this->open('2026-10-31', [10]);   // Saturday, PDT
        $this->open('2026-11-01', [10]);   // Sunday, PST (week of Oct 26 includes the change)
        $res = $this->as($t)->postJson('/shopping/in-person/slots/copy-week', ['from_week_start' => '2026-10-26', 'weeks' => ['2026-11-02']])
            ->assertOk()->json('data');
        $this->assertSame(3, $res['copied']);

        $local = ShoppingSlot::orderBy('starts_at')->get()->map(fn ($s) => $s->local()->format('Y-m-d H:i'))->all();
        $this->assertSame(['2026-10-26 10:00', '2026-10-31 10:00', '2026-11-01 10:00', '2026-11-02 10:00', '2026-11-07 10:00', '2026-11-08 10:00'], $local);
        $utc = ShoppingSlot::orderBy('starts_at')->get()->map(fn ($s) => $s->starts_at->format('m-d H:i'))->all();
        $this->assertSame(['10-26 17:00', '10-31 17:00', '11-01 18:00', '11-02 18:00', '11-07 18:00', '11-08 18:00'], $utc);
    }

    // ---------- final billing ----------

    private function completed(float $hours, float $spent, ?User $customer = null): ShoppingReservation
    {
        $this->open('2026-10-03', [11]);
        $r = $this->reserve($customer ?? $this->customer(), '2026-10-03', 11);
        $this->pay($r);
        $this->assertTrue($this->svc()->complete($r, $hours, $spent));

        return $r->fresh();
    }

    private function invoiceWebhook(array $meta, string $id = 'in_final_1')
    {
        $payload = json_encode(['id' => 'evt_' . uniqid(), 'object' => 'event', 'type' => 'invoice.paid', 'data' => ['object' => [
            'id' => $id, 'object' => 'invoice', 'amount_paid' => 8000, 'metadata' => $meta,
        ]]]);
        $t = time();
        $sig = hash_hmac('sha256', "$t.$payload", 'whsec_test');

        return $this->call('POST', '/webhooks/stripe-shopping', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t=$t,v1=$sig", 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_complete_sets_completed_at_and_payload_has_final_preview(): void
    {
        $r = $this->completed(3, 200);
        $this->assertNotNull($r->completed_at);
        $final = $this->as($this->staff('admin'))->getJson("/admin/in-person/reservations/{$r->id}")->assertOk()->json('data.final');
        $this->assertEquals(['hours_worked' => 3.0, 'amount_spent_usd' => 200.0, 'hours_fee_usd' => 90.0, 'commission_percent' => 10.0,
            'commission_usd' => 20.0, 'credit_usd' => 30.0, 'total_usd' => 80.0, 'status' => 'pending',
            'invoice_url' => null, 'sent_at' => null, 'paid_at' => null], $final);
    }

    public function test_final_is_null_until_completed(): void
    {
        $this->open('2026-10-03', [11]);
        $r = $this->reserve($this->customer(), '2026-10-03', 11);
        $this->assertNull($r->toApi()['final']);
        $this->assertNull($r->toApi(true)['final']);
    }

    public function test_final_invoice_creates_credit_line_sends_mail_and_is_idempotent(): void
    {
        $r = $this->completed(3, 200);
        $t = $this->staff('employee', 'shopping');
        $res = $this->as($t)->postJson("/shopping/in-person/reservations/{$r->id}/final-invoice")->assertOk();
        $this->assertSame('sent', $res->json('data.final.status'));
        $this->assertEquals(80, $res->json('data.final.total_usd'));
        $this->assertSame('https://invoice.stripe.test/in_final_1', $res->json('data.final.invoice_url'));

        $inv = $this->stripe->invoices[0];
        $this->assertSame('cus_test', $inv['customer']);
        $this->assertSame('in_person_final_invoice', $inv['params']['metadata']['type']);
        $this->assertSame((string) $r->id, $inv['params']['metadata']['reservation_id']);
        $this->assertSame([9000, 2000, -3000], array_column($inv['lines'], 'amount'));
        $this->assertSame(8000, array_sum(array_column($inv['lines'], 'amount')));
        $this->assertStringContainsString('Reservation already paid', $inv['lines'][2]['description']);

        $r = $r->fresh();
        $this->assertSame('in_final_1', $r->final_invoice_id);
        $this->assertEquals(80, $r->final_amount_usd);
        $this->assertNotNull($r->final_invoice_sent_at);
        Mail::assertQueued(InPersonFinalInvoice::class, 1);

        $this->as($t)->postJson("/shopping/in-person/reservations/{$r->id}/final-invoice")->assertStatus(422);
        $this->assertCount(1, $this->stripe->invoices);
        Mail::assertQueued(InPersonFinalInvoice::class, 1);
    }

    public function test_final_invoice_mail_renders_es_and_en(): void
    {
        foreach (['es' => 'Pagar factura', 'en' => 'Pay invoice'] as $lang => $needle) {
            $r = $this->completed(3, 200, $this->customer(['preferred_language' => $lang]));
            $this->svc()->createFinalInvoice($r);
            $html = (new InPersonFinalInvoice($r->fresh(['user'])))->render();
            $this->assertStringContainsString($needle, $html);
            $this->assertStringContainsString('80.00', $html);
            $this->assertStringContainsString('invoice.stripe.test', $html);
            $this->closeSlots();
        }
    }

    private function closeSlots(): void
    {
        ShoppingReservationSlot::query()->delete();
        ShoppingSlot::query()->delete();
    }

    public function test_final_invoice_total_not_positive_is_settled_without_stripe(): void
    {
        $r = $this->completed(0.5, 5); // 15 + 0.5 - 30 < 0
        $res = $this->as($this->staff('admin'))->postJson("/admin/in-person/reservations/{$r->id}/final-invoice")->assertOk();
        $this->assertSame('settled', $res->json('data.final.status'));
        $this->assertEquals(0, $res->json('data.final.total_usd'));
        $this->assertNotNull($res->json('data.final.paid_at'));
        $this->assertCount(0, $this->stripe->invoices);
        Mail::assertNotQueued(InPersonFinalInvoice::class);
    }

    public function test_final_invoice_refused_when_not_completed(): void
    {
        $this->open('2026-10-03', [11]);
        $r = $this->reserve($this->customer(), '2026-10-03', 11);
        $t = $this->staff('admin');
        $this->as($t)->postJson("/admin/in-person/reservations/{$r->id}/final-invoice")->assertStatus(422);
        $this->pay($r);
        $this->as($t)->postJson("/admin/in-person/reservations/{$r->id}/final-invoice")->assertStatus(422);
        $this->assertCount(0, $this->stripe->invoices);
    }

    public function test_final_invoice_stripe_failure_502_stores_nothing(): void
    {
        $r = $this->completed(3, 200);
        $this->stripe->failInvoice = true;
        $this->as($this->staff('admin'))->postJson("/admin/in-person/reservations/{$r->id}/final-invoice")->assertStatus(502);
        $r = $r->fresh();
        $this->assertNull($r->final_invoice_id);
        $this->assertNull($r->final_invoice_sent_at);
        $this->assertNull($r->final_amount_usd);
        Mail::assertNotQueued(InPersonFinalInvoice::class);
        $this->stripe->failInvoice = false;
        $this->as($this->staff('admin'))->postJson("/admin/in-person/reservations/{$r->id}/final-invoice")->assertOk();
    }

    public function test_final_invoice_mail_queue_failure_is_tolerated(): void
    {
        $r = $this->completed(3, 200);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('queue down'));
        Log::spy();
        $this->as($this->staff('admin'))->postJson("/admin/in-person/reservations/{$r->id}/final-invoice")->assertOk();
        $this->assertSame('in_final_1', $r->fresh()->final_invoice_id);
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'queue in-person'))->once();
    }

    public function test_invoice_paid_webhook_marks_final_paid_idempotently_and_ignores_other_types(): void
    {
        $r = $this->completed(3, 200);
        $this->as($this->staff('admin'))->postJson("/admin/in-person/reservations/{$r->id}/final-invoice")->assertOk();

        $this->invoiceWebhook(['type' => 'purchase_request_invoice', 'reservation_id' => (string) $r->id])->assertOk();
        $this->assertNull($r->fresh()->final_paid_at);

        $this->invoiceWebhook(['type' => 'in_person_final_invoice', 'reservation_id' => (string) $r->id])->assertOk();
        $paidAt = $r->fresh()->final_paid_at;
        $this->assertNotNull($paidAt);
        $this->travel(5)->minutes();
        $this->invoiceWebhook(['type' => 'in_person_final_invoice', 'reservation_id' => (string) $r->id])->assertOk();
        $this->assertEquals($paidAt, $r->fresh()->final_paid_at);
        $this->assertSame('paid', $r->fresh()->final()['status']);
        $this->assertSame('completed', $r->fresh()->status);
    }

    public function test_final_authz_and_customer_visibility(): void
    {
        $owner = $this->customer();
        $r = $this->completed(3, 200, $owner);
        foreach (['/shopping', '/admin'] as $prefix) {
            $this->as($owner)->getJson("$prefix/in-person/reservations/{$r->id}")->assertStatus(403);
            $this->as($owner)->postJson("$prefix/in-person/reservations/{$r->id}/final-invoice")->assertStatus(403);
        }
        $this->as($this->staff('employee', 'shopping'))->getJson("/shopping/in-person/reservations/{$r->id}")->assertOk()->assertJsonPath('data.customer.id', $owner->id);
        $this->as($this->staff('admin'))->getJson("/admin/in-person/reservations/{$r->id}")->assertOk();
        $this->as($this->staff('admin'))->getJson('/admin/in-person/reservations/99999')->assertStatus(404);

        $this->as($this->customer())->getJson('/in-person/reservations/' . $r->reservation_number)->assertStatus(404);
        $mine = $this->as($owner)->getJson('/in-person/reservations/' . $r->reservation_number)->assertOk()->json('data');
        $this->assertEquals(80, $mine['final']['total_usd']);
        $this->assertArrayNotHasKey('stripe_payment_intent_id', $mine);
        $this->assertArrayNotHasKey('cancel_reason', $mine);
        $this->assertArrayNotHasKey('customer', $mine);
    }

    public function test_customer_list_is_only_own_newest_first(): void
    {
        $me = $this->customer();
        $this->open('2026-10-03', [11, 12]);
        $this->open('2026-10-05', [11]);
        $a = $this->reserve($me, '2026-10-03', 11);
        $b = $this->reserve($me, '2026-10-05', 11);
        $this->reserve($this->customer(), '2026-10-03', 12);
        $list = $this->as($me)->getJson('/in-person/reservations')->assertOk()->json('data');
        $this->assertSame([$b->reservation_number, $a->reservation_number], array_column($list, 'reservation_number'));
    }

    public function test_events_are_chronological_and_only_present_ones(): void
    {
        $r = $this->completed(3, 200);
        $this->travel(1)->hours();
        $this->svc()->createFinalInvoice($r->fresh());
        $this->travel(1)->hours();
        $this->invoiceWebhook(['type' => 'in_person_final_invoice', 'reservation_id' => (string) $r->id])->assertOk();
        $events = $r->fresh()->toApi()['events'];
        $this->assertSame(['reserved', 'paid', 'completed', 'final_invoice_sent', 'final_invoice_paid'], array_column($events, 'type'));
        $this->assertSame('in_person.event.paid', $events[1]['label_key']);
        $this->assertStringEndsWith('+00:00', $events[0]['at']);
        $this->assertSame($events, collect($events)->sortBy('at')->values()->all());
    }
}
