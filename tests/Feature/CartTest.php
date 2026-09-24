<?php

namespace Tests\Feature;

use App\Mail\PurchaseRequestCreated;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\LiveShoppingTestCase;

/**
 * The Boxly cart (C2): GET/POST/PATCH/DELETE /cart and POST /cart/finalize.
 *
 * Builds on LiveShoppingTestCase, the one base here that can run the REAL users +
 * conversations migrations on in-memory SQLite. The carts tables come from the
 * real C2 migration. purchase_requests is hand-rolled: its migration chain alters
 * order_items and later issues raw MySQL ENUM rewrites SQLite cannot run, so the
 * test builds only the columns PurchaseRequestIntake writes.
 *
 *   vendor/bin/phpunit tests/Feature/CartTest.php
 */
class CartTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Boxly Lab: the cart is for allowlisted testers; every user here is one.
        config(['services.boxly_beta.emails' => ['*']]);

        $this->artisan('migrate', [
            '--path'  => 'database/migrations/2026_04_29_000007_add_team_to_users.php',
            '--force' => true,
        ]);

        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id');
            $t->foreignId('conversation_id')->nullable();
            $t->string('request_number')->unique();
            $t->string('status')->default('pending_review');
            $t->string('source')->default('assisted');
            $t->string('currency', 3)->default('usd');
            $t->text('customer_notes')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_request_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_request_id');
            $t->string('product_name');
            $t->text('product_url')->nullable();
            $t->text('product_image_url')->nullable();
            $t->decimal('price', 10, 2)->nullable();
            $t->integer('quantity')->default(1);
            $t->json('options')->nullable();
            $t->text('notes')->nullable();
            $t->string('image_path')->nullable();
            $t->string('image_filename')->nullable();
            $t->string('image_mime_type')->nullable();
            $t->integer('image_size')->nullable();
            $t->text('image_url')->nullable();
            $t->timestamps();
        });

        $this->artisan('migrate', [
            '--path'  => 'database/migrations/2026_09_23_000000_create_carts_tables.php',
            '--force' => true,
        ]);

        Mail::fake();
        Storage::fake('spaces');
        Http::fake(['*' => Http::response('img', 200, ['Content-Type' => 'image/jpeg'])]);
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'store_id' => 'nike',
            'store_name' => 'Nike',
            'product_url' => 'https://www.nike.com/t/air-max-90',
            'title' => 'Air Max 90',
            'image_url' => 'https://static.nike.com/a.jpg',
            'price' => 130,
            'source' => 'chat',
        ], $overrides);
    }

    private function customer(): User
    {
        return User::factory()->createQuietly();
    }

    public function test_auth_required(): void
    {
        $this->getJson('/cart')->assertStatus(401);
        $this->postJson('/cart/items', $this->item())->assertStatus(401);
        $this->patchJson('/cart/items/1', ['quantity' => 2])->assertStatus(401);
        $this->deleteJson('/cart/items/1')->assertStatus(401);
        $this->postJson('/cart/finalize')->assertStatus(401);
    }

    public function test_empty_get_does_not_create_a_cart(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->getJson('/cart')
            ->assertOk()
            ->assertJsonPath('data.id', null)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.stores', [])
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonPath('data.subtotal', 0);

        $this->assertSame(0, Cart::count());
    }

    public function test_add_creates_the_cart_and_returns_item_and_cart(): void
    {
        $u = $this->customer();
        $conv = Conversation::create(['user_id' => $u->id, 'title' => 'hilo']);

        $r = $this->actingAs($u)->postJson('/cart/items', $this->item([
            'variants' => ['Size' => '10', 'Color' => 'Black'],
            'conversation_id' => $conv->id,
            'saved_id' => 'sv_1',
        ]))->assertStatus(201);

        $r->assertJsonPath('data.item.store_id', 'nike')
            ->assertJsonPath('data.item.title', 'Air Max 90')
            ->assertJsonPath('data.item.price', 130)
            ->assertJsonPath('data.item.currency', 'USD')
            ->assertJsonPath('data.item.quantity', 1)
            ->assertJsonPath('data.item.variants', ['Size' => '10', 'Color' => 'Black'])
            ->assertJsonPath('data.item.source', 'chat')
            ->assertJsonPath('data.item.saved_id', 'sv_1')
            ->assertJsonPath('data.item.sync_status', 'pending')
            ->assertJsonPath('data.cart.status', 'open')
            ->assertJsonPath('data.cart.conversation_id', $conv->id)
            ->assertJsonPath('data.cart.item_count', 1);

        $this->assertNotNull($r->json('data.cart.id'));
        $this->assertSame(1, Cart::where('user_id', $u->id)->where('active_slot', 1)->count());

        // Empty variants serialize as a JSON object, not [].
        $raw = $this->actingAs($u)->postJson('/cart/items', $this->item(['product_url' => 'https://www.nike.com/t/cortez']))
            ->assertStatus(201)->getContent();
        $this->assertStringContainsString('"variants":{}', $raw);
    }

    public function test_someone_elses_conversation_is_ignored(): void
    {
        $u = $this->customer();
        $theirs = Conversation::create(['user_id' => $this->customer()->id, 'title' => 'x']);

        $this->actingAs($u)->postJson('/cart/items', $this->item(['conversation_id' => $theirs->id]))
            ->assertStatus(201)
            ->assertJsonPath('data.cart.conversation_id', null);
    }

    public function test_same_url_and_normalized_variants_increments_capped_at_20(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['Size' => 'M', 'Color' => 'Red'], 'quantity' => 2]))->assertStatus(201);
        $r = $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['color' => ' red ', 'size' => 'm'], 'quantity' => 3]))
            ->assertStatus(201);

        $r->assertJsonPath('data.item.quantity', 5)->assertJsonPath('data.cart.item_count', 5);
        $this->assertSame(1, CartItem::count());

        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['Size' => 'M', 'Color' => 'Red'], 'quantity' => 20]))
            ->assertStatus(201)
            ->assertJsonPath('data.item.quantity', 20);
    }

    public function test_different_variants_adds_a_row(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['Size' => 'M']]))->assertStatus(201);
        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['Size' => 'L']]))
            ->assertStatus(201)
            ->assertJsonCount(2, 'data.cart.items');
    }

    public function test_store_grouping_and_subtotal(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->postJson('/cart/items', $this->item(['price' => 10.50, 'quantity' => 2]))->assertStatus(201);
        $this->actingAs($u)->postJson('/cart/items', $this->item([
            'store_id' => 'target', 'store_name' => 'Target',
            'product_url' => 'https://www.target.com/p/x', 'price' => null,
        ]))->assertStatus(201);
        $this->actingAs($u)->postJson('/cart/items', $this->item([
            'product_url' => 'https://www.nike.com/t/cortez', 'price' => 5, 'quantity' => 1,
        ]))->assertStatus(201);

        $r = $this->actingAs($u)->getJson('/cart')->assertOk();

        $r->assertJsonPath('data.item_count', 4)
            ->assertJsonPath('data.subtotal', 26)
            ->assertJsonPath('data.has_unpriced', true)
            ->assertJsonPath('data.stores.0.store_id', 'nike')
            ->assertJsonPath('data.stores.0.item_count', 3)
            ->assertJsonPath('data.stores.0.subtotal', 26)
            ->assertJsonPath('data.stores.0.has_unpriced', false)
            ->assertJsonPath('data.stores.1.store_id', 'target')
            ->assertJsonPath('data.stores.1.store_name', 'Target')
            ->assertJsonPath('data.stores.1.subtotal', 0)
            ->assertJsonPath('data.stores.1.has_unpriced', true);
        $this->assertNotNull($r->json('data.updated_at'));
    }

    public function test_patch_and_delete_only_own_open_items(): void
    {
        $u = $this->customer();
        $other = $this->customer();

        $mine = $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['Size' => 'M']]))->json('data.item.id');
        $theirs = $this->actingAs($other)->postJson('/cart/items', $this->item())->json('data.item.id');

        $this->actingAs($u)->patchJson("/cart/items/{$theirs}", ['quantity' => 3])->assertStatus(404);
        $this->actingAs($u)->deleteJson("/cart/items/{$theirs}")->assertStatus(404);
        $this->actingAs($u)->patchJson('/cart/items/999999', ['quantity' => 3])->assertStatus(404);

        $this->actingAs($u)->patchJson("/cart/items/{$mine}", ['quantity' => 0])->assertStatus(422);
        $this->actingAs($u)->patchJson("/cart/items/{$mine}", ['quantity' => 21])->assertStatus(422);

        $this->actingAs($u)->patchJson("/cart/items/{$mine}", ['quantity' => 4, 'variants' => ['Size' => 'L']])
            ->assertOk()
            ->assertJsonPath('data.item.quantity', 4)
            ->assertJsonPath('data.item.variants', ['Size' => 'L'])
            ->assertJsonPath('data.cart.item_count', 4);
        $this->assertSame('size=l', CartItem::find($mine)->variants_key);

        // Changing to a selection already in the cart is refused, not duplicated.
        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['Size' => 'S']]))->assertStatus(201);
        $this->actingAs($u)->patchJson("/cart/items/{$mine}", ['variants' => ['size' => 's']])
            ->assertStatus(422)->assertJsonValidationErrors('variants');

        $this->actingAs($u)->deleteJson("/cart/items/{$mine}")
            ->assertOk()
            ->assertJsonCount(1, 'data.cart.items');

        $this->assertNotNull(CartItem::find($theirs));
    }

    public function test_validation_https_only_store_id_regex_and_variants_shape(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->postJson('/cart/items', $this->item(['product_url' => 'http://www.nike.com/t/x']))
            ->assertStatus(422)->assertJsonValidationErrors('product_url');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['product_url' => 'javascript:alert(1)']))
            ->assertStatus(422)->assertJsonValidationErrors('product_url');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['image_url' => 'http://img.test/a.jpg']))
            ->assertStatus(422)->assertJsonValidationErrors('image_url');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['store_id' => 'Nike']))
            ->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['store_id' => '-nike']))
            ->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['store_id' => str_repeat('a', 41)]))
            ->assertStatus(422)->assertJsonValidationErrors('store_id');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['source' => 'email']))
            ->assertStatus(422)->assertJsonValidationErrors('source');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['quantity' => 21]))
            ->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['a', 'b']]))
            ->assertStatus(422)->assertJsonValidationErrors('variants');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g'], 'x')]))
            ->assertStatus(422)->assertJsonValidationErrors('variants');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => [str_repeat('k', 41) => 'x']]))
            ->assertStatus(422)->assertJsonValidationErrors('variants');
        $this->actingAs($u)->postJson('/cart/items', $this->item(['variants' => ['Size' => str_repeat('v', 121)]]))
            ->assertStatus(422)->assertJsonValidationErrors('variants');

        $this->assertSame(0, Cart::count());
    }

    public function test_finalize_empty_or_missing_cart_is_422(): void
    {
        $u = $this->customer();

        $this->actingAs($u)->postJson('/cart/finalize')->assertStatus(422)->assertJsonValidationErrors('cart');

        $id = $this->actingAs($u)->postJson('/cart/items', $this->item())->json('data.item.id');
        $this->actingAs($u)->deleteJson("/cart/items/{$id}")->assertOk();
        $this->actingAs($u)->postJson('/cart/finalize')->assertStatus(422);

        $this->assertSame(0, PurchaseRequest::count());
    }

    public function test_finalize_creates_one_purchase_request_and_closes_the_cart(): void
    {
        $u = $this->customer();
        $conv = Conversation::create(['user_id' => $u->id, 'title' => 'hilo']);

        $this->actingAs($u)->postJson('/cart/items', $this->item([
            'variants' => ['size' => '10', 'color' => 'Black', 'Width' => 'Wide'],
            'quantity' => 2,
            'conversation_id' => $conv->id,
        ]))->assertStatus(201);
        $this->actingAs($u)->postJson('/cart/items', $this->item([
            'store_id' => 'target', 'store_name' => null,
            'product_url' => 'https://www.target.com/p/mug', 'title' => 'Mug', 'price' => null, 'image_url' => null,
        ]))->assertStatus(201);
        $cartId = Cart::where('user_id', $u->id)->value('id');

        $r = $this->actingAs($u)->postJson('/cart/finalize', ['notes' => 'Envolver para regalo'])
            ->assertStatus(201);

        $pr = PurchaseRequest::with('items')->findOrFail($r->json('data.purchase_request_id'));
        $r->assertJsonPath('data.request_number', $pr->request_number)
            ->assertJsonPath('data.cart.id', null)
            ->assertJsonPath('data.cart.items', []);

        $this->assertSame(1, PurchaseRequest::count());
        $this->assertSame($u->id, $pr->user_id);
        $this->assertSame(PurchaseRequest::STATUS_PENDING_REVIEW, $pr->status);
        $this->assertSame('usd', $pr->currency);
        $this->assertSame($conv->id, $pr->conversation_id);
        $this->assertSame('Envolver para regalo', $pr->customer_notes);
        $this->assertCount(2, $pr->items);

        [$shoe, $mug] = [$pr->items[0], $pr->items[1]];
        $this->assertSame('Air Max 90', $shoe->product_name);
        $this->assertSame('https://www.nike.com/t/air-max-90', $shoe->product_url);
        $this->assertSame('https://static.nike.com/a.jpg', $shoe->product_image_url);
        $this->assertEquals(130, $shoe->price);
        $this->assertSame(2, (int) $shoe->quantity);
        $this->assertSame(['Talla' => '10', 'Color' => 'Black', 'Width' => 'Wide'], $shoe->options);
        $this->assertSame('Tienda: Nike', $shoe->notes);

        $this->assertSame('Mug', $mug->product_name);
        $this->assertNull($mug->price);
        $this->assertNull($mug->options);
        $this->assertSame('Tienda: target', $mug->notes);

        $cart = Cart::findOrFail($cartId);
        $this->assertSame(Cart::STATUS_FINALIZED, $cart->status);
        $this->assertNull($cart->active_slot);
        $this->assertSame($pr->id, $cart->purchase_request_id);

        Mail::assertQueued(PurchaseRequestCreated::class);

        // The finalized cart is history: GET shows an empty open cart, its
        // items are no longer editable, and the next add opens a fresh cart.
        $this->actingAs($u)->getJson('/cart')->assertOk()
            ->assertJsonPath('data.id', null)
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.item_count', 0);
        $oldItem = CartItem::where('cart_id', $cartId)->value('id');
        $this->actingAs($u)->patchJson("/cart/items/{$oldItem}", ['quantity' => 3])->assertStatus(404);
        $this->actingAs($u)->postJson('/cart/finalize')->assertStatus(422);

        $newCartId = $this->actingAs($u)->postJson('/cart/items', $this->item())->json('data.cart.id');
        $this->assertNotSame($cartId, $newCartId);
    }

    /** POST /purchase-requests now runs through the same PurchaseRequestIntake. */
    public function test_classic_purchase_request_store_still_works(): void
    {
        $u = $this->customer();

        $r = $this->actingAs($u)->postJson('/purchase-requests', ['items' => [
            ['product_url' => 'https://www.target.com/p/starbucks-pumpkin-spice/-/A-53409621', 'quantity' => 2, 'options' => ['Talla' => 'M']],
            ['product_name' => '', 'product_url' => '', 'quantity' => 1], // blank paste row, dropped
        ]])->assertStatus(201)->assertJsonPath('success', true);

        $pr = PurchaseRequest::with('items')->findOrFail($r->json('data.id'));
        $this->assertSame(PurchaseRequest::STATUS_PENDING_REVIEW, $pr->status);
        $this->assertCount(1, $pr->items);
        $this->assertSame('Starbucks Pumpkin Spice', $pr->items[0]->product_name);
        $this->assertSame(['Talla' => 'M'], $pr->items[0]->options);
        Mail::assertQueued(PurchaseRequestCreated::class);

        $this->actingAs($u)->postJson('/purchase-requests', ['items' => [['product_name' => '', 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonPath('success', false);
        $this->assertSame(1, PurchaseRequest::count());
    }

    public function test_one_open_cart_per_user(): void
    {
        $u = $this->customer();

        $a = $this->actingAs($u)->postJson('/cart/items', $this->item())->json('data.cart.id');
        $b = $this->actingAs($u)->postJson('/cart/items', $this->item(['product_url' => 'https://www.nike.com/t/cortez']))->json('data.cart.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Cart::where('user_id', $u->id)->count());

        // The database, not just the controller, refuses a second open cart.
        $this->expectException(QueryException::class);
        Cart::create(['user_id' => $u->id, 'status' => 'open', 'active_slot' => 1]);
    }
}
