<?php

namespace App\Services;

use App\Models\ConversationMessage;
use App\Models\PurchaseRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE SHOPPERS STOP (Alex 2026-11-13: "a funnel view in the admin"): each AI-search chat's furthest stage, read from its own saved
 * messages — results shown (a gallery with products) → product opened (a picker) → added to the box (show_shipment) → Finalizar
 * (finalize_order that went through) → paid (its purchase request paid). Read-only.
 */
class AiSearchFunnel
{
    public const STAGES = ['results', 'opened', 'added', 'finalized', 'paid'];

    private const GALLERY_TOOLS = ['tool-live_results', 'tool-show_products', 'tool-show_saved_products', 'tool-browse_store', 'tool-browse_stores', 'tool-curate_products', 'tool-show_collection'];

    /** @return array<int, array<string, bool>> conversation id → stage → reached */
    public static function forConversations(Collection|array $ids): array
    {
        $ids = collect($ids)->map(fn ($i) => (int) $i)->filter()->unique()->values();
        $out = $ids->mapWithKeys(fn ($id) => [$id => array_fill_keys(self::STAGES, false)])->all();
        if ($ids->isEmpty()) {
            return [];
        }
        ConversationMessage::whereIn('conversation_id', $ids)->where('role', 'assistant')->select(['conversation_id', 'content'])
            ->orderBy('id')->chunk(500, function ($rows) use (&$out) {
                foreach ($rows as $m) {
                    $parts = is_array($m->content) ? ($m->content['parts'] ?? (array_is_list($m->content) ? $m->content : [])) : [];
                    foreach ($parts as $p) {
                        $type = is_array($p) ? ($p['type'] ?? '') : '';
                        $o = is_array($p['output'] ?? null) ? $p['output'] : [];
                        $s = &$out[$m->conversation_id];
                        if (in_array($type, self::GALLERY_TOOLS, true) && ! empty($o['products'])) {
                            $s['results'] = true;
                        } elseif ($type === 'tool-product_picker' || $type === 'tool-get_product_variants') {
                            $s['opened'] = true;
                        } elseif ($type === 'tool-show_shipment' && empty($p['errorText']) && ($o['ok'] ?? true) !== false) {
                            $s['added'] = true;
                        } elseif ($type === 'tool-finalize_order' && empty($p['errorText']) && empty($o['error']) && ($o['ok'] ?? true) !== false) {
                            $s['finalized'] = true;
                        }
                        unset($s);
                    }
                }
            });
        $paid = ! Schema::hasTable('purchase_requests') ? collect() : PurchaseRequest::whereIn('conversation_id', $ids)
            ->where(fn ($q) => $q->whereNotNull('paid_at')->orWhereIn('status', [PurchaseRequest::STATUS_PAID, PurchaseRequest::STATUS_PURCHASED]))
            ->pluck('conversation_id')->unique();
        foreach ($paid as $id) {
            if (isset($out[(int) $id])) {
                $out[(int) $id]['paid'] = true;
            }
        }
        // a later stage implies the earlier ones (a pasted link skips the gallery; a paid order was finalized)
        foreach ($out as &$s) {
            $reached = false;
            foreach (array_reverse(self::STAGES) as $st) {
                $reached = $reached || $s[$st];
                $s[$st] = $reached;
            }
        }

        return $out;
    }

    /** Totals over the chats: how many reached each stage. */
    public static function totals(array $byConversation): array
    {
        $t = ['chats' => count($byConversation)] + array_fill_keys(self::STAGES, 0);
        foreach ($byConversation as $s) {
            foreach (self::STAGES as $st) {
                $t[$st] += $s[$st] ? 1 : 0;
            }
        }

        return $t;
    }
}
