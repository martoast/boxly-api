<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\PurchaseRequest;
use App\Models\User;
use Tests\LiveShoppingTestCase;

/** The admin AI-search funnel (2026-11-13): results → opened → added → finalized → paid, per chat and in total. */
class AiSearchFunnelTest extends LiveShoppingTestCase
{
    private function chat(User $u, array $parts): Conversation
    {
        $c = Conversation::create(['user_id' => $u->id, 'title' => 't']);
        ConversationMessage::create(['conversation_id' => $c->id, 'role' => 'assistant', 'content' => ['parts' => $parts]]);

        return $c;
    }

    public function test_each_chat_reaches_its_furthest_stage_and_the_totals_add_up(): void
    {
        $admin = User::factory()->createQuietly(['role' => 'admin']);
        $u = User::factory()->createQuietly(['email' => 'shopper@example.com']);
        $lab = User::factory()->createQuietly(['email' => 'alex+boxlylab@example.com']);
        $gallery = ['type' => 'tool-live_results', 'output' => ['products' => [['title' => 'x']]]];
        $browsed = $this->chat($u, [$gallery]);
        $opened = $this->chat($u, [$gallery, ['type' => 'tool-product_picker', 'output' => []]]);
        $finalized = $this->chat($u, [$gallery, ['type' => 'tool-show_shipment', 'output' => []], ['type' => 'tool-finalize_order', 'output' => ['ok' => true]]]);
        $empty = $this->chat($u, [['type' => 'tool-live_results', 'output' => ['products' => []]]]);
        $this->chat($lab, [$gallery]); // internal: left out of the totals

        $r = $this->actingAs($admin)->getJson('/admin/ai-search/funnel')->assertOk();
        $this->assertSame(['chats' => 4, 'results' => 3, 'opened' => 2, 'added' => 1, 'finalized' => 1, 'paid' => 0], $r->json('data'));

        $rows = collect($this->actingAs($admin)->getJson('/admin/ai-search/conversations?per_page=10')->assertOk()->json('data.data'))->keyBy('id');
        $this->assertSame(['results' => true, 'opened' => false, 'added' => false, 'finalized' => false, 'paid' => false], $rows[$browsed->id]['funnel']);
        $this->assertTrue($rows[$finalized->id]['funnel']['added']);
        $this->assertFalse($rows[$empty->id]['funnel']['results'], 'a gallery with no products is not results');
        $this->assertTrue($rows[$opened->id]['funnel']['opened']);
    }
}
