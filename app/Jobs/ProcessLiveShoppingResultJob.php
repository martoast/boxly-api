<?php

namespace App\Jobs;

use App\Models\ConversationMessage;
use App\Models\LiveShoppingSession;
use App\Models\LiveShoppingWebhookReceipt;
use App\Models\SearchEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Apply one durable inbox receipt.
 *
 * Takes a RECEIPT ID and nothing else: it does not insert the receipt, does not
 * re-verify signatures and never reads the HTTP request, so it is safely
 * re-runnable from a queue retry or from the drainer.
 *
 * Everything happens in ONE transaction. A half-applied terminal — the message
 * appended but the session still running, or the slot released without the
 * message — is the failure mode this exists to prevent.
 */
class ProcessLiveShoppingResultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $receiptId)
    {
    }

    public function handle(): void
    {
        if (! Schema::hasTable('live_shopping_webhook_receipts')) {
            return;   // deploy window; the drainer picks it up afterwards
        }

        DB::transaction(function () {
            $receipt = LiveShoppingWebhookReceipt::where('id', $this->receiptId)
                ->lockForUpdate()->first();

            // Not 'received' means another worker already owns it. This status
            // transition under a row lock is the whole answer to duplicate
            // dispatch (queue retry, or the drainer racing the fast path).
            if (! $receipt || $receipt->status !== LiveShoppingWebhookReceipt::STATUS_RECEIVED) {
                return;
            }

            $payload = $receipt->payload ?? [];

            $session = LiveShoppingSession::where('engine_session_id', $payload['session_id'] ?? '')
                ->lockForUpdate()->first();

            if (! $session) {
                // NOT necessarily an orphan. A fast engine can deliver terminal
                // after durable acceptance but BEFORE the create request has
                // stored engine_session_id — the delivery legitimately arrives
                // first. Marking it processed here would drop a real result
                // forever, so a young receipt stays retryable and the drainer
                // brings it back.
                $horizon = $this->orphanHorizon();
                if ($receipt->received_at && $receipt->received_at->gt(now()->subSeconds($horizon))) {
                    $receipt->forceFill(['attempts' => $receipt->attempts + 1])->save();

                    return;   // still 'received' — try again after the drain grace
                }

                Log::warning('live-shopping delivery orphaned: no session after horizon', [
                    'delivery_id' => $receipt->delivery_id,
                    'attempts'    => $receipt->attempts,
                    'horizon'     => $horizon,
                ]);

                $this->close($receipt, LiveShoppingWebhookReceipt::STATUS_FAILED, 'orphaned');

                return;
            }

            $receipt->live_shopping_session_id = $session->id;
            $receipt->attempts = $receipt->attempts + 1;

            // Correlation. A local NULL is the deleted-thread case (the FK is
            // nullOnDelete and can fire mid-session): skip the append, still
            // complete the terminal transition. A genuine MISMATCH is a
            // misrouted delivery: record it and change nothing else.
            $conversationDeleted = $session->conversation_id === null;
            if (! $conversationDeleted
                && (string) $session->conversation_id !== (string) ($payload['conversation_id'] ?? '')) {
                Log::warning('live-shopping delivery correlation mismatch', [
                    'delivery_id' => $receipt->delivery_id,
                    'session_id'  => $session->id,
                ]);
                $this->close($receipt, LiveShoppingWebhookReceipt::STATUS_CONFLICT, 'correlation_mismatch');

                return;
            }

            // STRICTLY greater than the floor recorded at create. Equality is
            // stale, not fresh: latest_seq is the sequence already observed when
            // the session was accepted.
            $seq = $payload['terminal_seq'] ?? null;
            if (! is_int($seq) || $seq < 1
                || ($session->latest_seq !== null && $seq <= $session->latest_seq)) {
                Log::warning('live-shopping delivery with stale terminal_seq', [
                    'delivery_id' => $receipt->delivery_id, 'terminal_seq' => $seq,
                ]);
                $this->close($receipt, LiveShoppingWebhookReceipt::STATUS_CONFLICT, 'stale_seq');

                return;
            }

            // Terminal states are ABSORBING. First terminal delivery wins.
            if ($session->isTerminal()) {
                // Distinguish the two cases rather than calling both "processed":
                // re-delivery of the SAME terminal event is an idempotent replay,
                // but a DIFFERENT delivery contradicting the recorded outcome is
                // a genuine conflict and must be visible as one.
                $sameDelivery = $session->terminal_delivery_id === $receipt->delivery_id;
                $this->close(
                    $receipt,
                    $sameDelivery
                        ? LiveShoppingWebhookReceipt::STATUS_PROCESSED
                        : LiveShoppingWebhookReceipt::STATUS_CONFLICT,
                    $sameDelivery ? null : 'already_terminal',
                );

                return;
            }

            $part = $payload['assistant_part'] ?? null;
            $products = $part['output']['products'] ?? [];
            $outcome = $payload['result']['outcome'] ?? LiveShoppingSession::STATUS_FAILED;

            // Append only when the thread still exists AND still belongs to the
            // session's owner. A re-parented or corrupted row must never let one
            // customer's result land in another's conversation.
            if (! $conversationDeleted
                && is_array($part)
                && $session->conversation
                && $session->conversation->user_id === $session->user_id) {
                ConversationMessage::create([
                    'conversation_id' => $session->conversation_id,
                    'role'            => 'assistant',
                    // The `parts` wrapper is not cosmetic: deriveProducts reads
                    // $m->content['parts'], so a bare list is invisible to the
                    // rail. The part itself is stored EXACTLY as validated — no
                    // toolCallId or input, which were never in the contract.
                    'content'         => ['parts' => [$part]],
                ]);

                // The history sidebar orders by this; without the bump a live
                // result never reorders the thread.
                $session->conversation->forceFill(['last_message_at' => now()])->save();
            } elseif (! $conversationDeleted) {
                Log::warning('live-shopping result not appended: conversation owner differs', [
                    'session_id' => $session->id,
                ]);
            }

            if (Schema::hasColumn('search_events', 'source')) {
                SearchEvent::create([
                    'user_id'         => $session->user_id,
                    'conversation_id' => $conversationDeleted ? null : $session->conversation_id,
                    'type'            => SearchEvent::TYPE_SEARCH,
                    'source'          => 'live_engine',
                    'query'           => mb_substr((string) $session->objective, 0, 1000),
                    'store'           => $session->store_id,
                    'results'         => count($products),
                ]);
            }

            $session->forceFill([
                'status'               => $outcome,
                'error_code'           => $payload['result']['error_code'] ?? null,
                'terminal_delivery_id' => $receipt->delivery_id,
                'terminal_seq'         => $seq,
                'active_slot'          => null,   // release the one-active-session slot
            ])->save();

            $this->close($receipt, LiveShoppingWebhookReceipt::STATUS_PROCESSED);
        });
    }

    /**
     * How long a delivery may arrive before its own session row is visible.
     * Bounds the create-vs-webhook race without letting a genuinely orphaned
     * receipt retry forever.
     */
    private function orphanHorizon(): int
    {
        return max(30, min(3600, (int) config('services.live_shopping_engine.orphan_horizon', 300)));
    }

    /**
     * Every terminating path lands here. A receipt left at 'received' after a
     * completed run would be re-dispatched by the drainer forever.
     */
    private function close(LiveShoppingWebhookReceipt $receipt, string $status, ?string $errorCode = null): void
    {
        $receipt->forceFill([
            'status'       => $status,
            'error_code'   => $errorCode,
            'processed_at' => now(),
        ])->save();
    }
}
