<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessLiveShoppingResultJob;
use App\Models\LiveShoppingWebhookReceipt;
use App\Services\LiveShoppingEngine;
use App\Support\LiveShoppingSignature;
use App\Support\ProductV1;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Terminal delivery from the live shopping engine.
 *
 * Authenticated by HMAC, not Sanctum. Its only job is to get a DURABLE INBOX row
 * committed and answer — all real work happens in ProcessLiveShoppingResultJob,
 * because the engine must never wait on Laravel doing conversation writes.
 *
 * The 202 is a durability promise: it says "this will happen". So it is not
 * returned until the receipt has committed.
 */
class LiveShoppingWebhookController extends Controller
{
    /** Generous for 24 bounded products, far below anything that hurts us. */
    private const MAX_BODY_BYTES = 256 * 1024;

    private const TERMINAL_KEYS = [
        'schema_version', 'delivery_id', 'session_id', 'terminal_seq',
        'conversation_id', 'occurred_at', 'result', 'assistant_part',
    ];

    public function __construct(private readonly LiveShoppingEngine $engine)
    {
    }

    public function handle(Request $request)
    {
        // Disabled or pre-migration: 404, not a 500 and not a half-accept.
        if (! $this->engine->enabled() || ! Schema::hasTable('live_shopping_webhook_receipts')) {
            abort(404);
        }

        $raw = $request->getContent();

        // Bound the bytes BEFORE hashing or decoding: neither should ever be
        // asked to chew through an unbounded body.
        if (! is_string($raw) || strlen($raw) > self::MAX_BODY_BYTES) {
            return response()->json(['ok' => false, 'error' => 'payload too large'], 413);
        }

        if (! $this->verify($request, $raw)) {
            return response()->json(['ok' => false, 'error' => 'invalid signature'], 403);
        }

        $payload = $this->validated(json_decode($raw, true));
        if ($payload === null) {
            return response()->json(['ok' => false, 'error' => 'invalid payload'], 422);
        }

        $hash = LiveShoppingSignature::hashBody($raw);

        // THE WRITE IS THE DECISION. A read-then-decide design is racy: two
        // concurrent same-id/different-body requests could both find nothing and
        // both answer 202, making the 409 unreachable. insertOrIgnore on the
        // unique delivery_id arbitrates; whoever loses reads the winner's row
        // inside this same transaction.
        $result = DB::transaction(function () use ($payload, $hash) {
            $inserted = LiveShoppingWebhookReceipt::insertOrIgnore([
                'delivery_id'    => $payload['delivery_id'],
                'content_sha256' => $hash,
                'payload'        => json_encode($payload),
                'status'         => LiveShoppingWebhookReceipt::STATUS_RECEIVED,
                'terminal_seq'   => $payload['terminal_seq'],
                'outcome'        => $payload['result']['outcome'],
                'received_at'    => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            if ($inserted === 0) {
                $existing = LiveShoppingWebhookReceipt::where('delivery_id', $payload['delivery_id'])->first();

                // Two different bodies claiming one delivery id means one of
                // them is wrong. Treating that as a duplicate would silently
                // drop real data, so it is a conflict, not a replay.
                if ($existing && ! hash_equals($existing->content_sha256, $hash)) {
                    return ['conflict' => true];
                }

                // A true replay is effect-free and gets the same ack it would
                // have got the first time. An engine retrying a delivery it is
                // unsure about is doing the right thing.
                return ['receipt' => null];
            }

            return ['receipt' => LiveShoppingWebhookReceipt::where('delivery_id', $payload['delivery_id'])->first()];
        });

        if (! empty($result['conflict'])) {
            Log::warning('live-shopping delivery conflict: same delivery_id, different body', [
                'delivery_id' => $payload['delivery_id'],
            ]);

            return response()->json(['ok' => false, 'error' => 'delivery_id conflict'], 409);
        }

        // Dispatched AFTER commit, and only as a latency optimisation: the
        // drainer (boxly:live-shopping-drain) is what guarantees the work, so a
        // crash here loses nothing.
        if ($result['receipt']) {
            ProcessLiveShoppingResultJob::dispatch($result['receipt']->id)->onConnection('database');
        }

        return response()->json(['ok' => true, 'data' => [
            'schema_version' => LiveShoppingEngine::SCHEMA_VERSION,
            'delivery_id'    => $payload['delivery_id'],
            'accepted'       => true,
        ]], 202);
    }

    /**
     * Verify the five frozen headers, cheapest first.
     *
     * The signature covers the body's HASH, not the body — so the content-hash
     * check is not belt-and-braces, it is the only thing binding the signed
     * envelope to the bytes we received. It runs BEFORE json_decode, so an
     * altered body never reaches the parser.
     */
    private function verify(Request $request, string $raw): bool
    {
        $keyId     = (string) $request->header('X-Boxly-Key-Id', '');
        $timestamp = (string) $request->header('X-Boxly-Timestamp', '');
        $nonce     = (string) $request->header('X-Boxly-Nonce', '');
        $bodyHash  = strtolower((string) $request->header('X-Boxly-Content-SHA256', ''));
        $signature = (string) $request->header('X-Boxly-Signature', '');

        $secret = $this->secretFor($keyId);
        if ($secret === null) {
            return false;   // unknown key id — this is what makes rotation possible
        }

        if ($timestamp === '' || ! ctype_digit($timestamp) || strlen($timestamp) > 12) {
            return false;
        }
        $skew = max(30, min(900, (int) config('services.live_shopping_engine.skew', 300)));
        if (abs(time() - (int) $timestamp) > $skew) {
            return false;
        }

        // Bounded syntax/length. The nonce is INSIDE the signature, so it cannot
        // be rewritten by a replayer; it prevents canonical ambiguity, while
        // delivery_id prevents repeated effects. No nonce cache is needed in P1.
        if ($nonce === '' || strlen($nonce) > 128 || ! preg_match('/^[A-Za-z0-9_.:-]+$/', $nonce)) {
            return false;
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $bodyHash)) {
            return false;
        }
        if (! hash_equals(LiveShoppingSignature::hashBody($raw), $bodyHash)) {
            return false;
        }

        return LiveShoppingSignature::matches(
            $signature,
            LiveShoppingSignature::inboundCanonical($timestamp, $nonce, $bodyHash),
            $secret,
        );
    }

    /** LIVE_SHOPPING_WEBHOOK_KEYS="k1:secret1,k2:secret2" */
    private function secretFor(string $keyId): ?string
    {
        if ($keyId === '' || strlen($keyId) > 64) {
            return null;
        }

        foreach (explode(',', (string) config('services.live_shopping_engine.webhook_keys', '')) as $pair) {
            $parts = explode(':', trim($pair), 2);
            if (count($parts) === 2 && hash_equals($parts[0], $keyId) && $parts[1] !== '') {
                return $parts[1];
            }
        }

        return null;
    }

    /**
     * Closed, strict TerminalWebhookV1 validation. Unknown version, unknown key
     * or a malformed field is a rejection, not a best-effort parse.
     *
     * assistant_part must agree EXACTLY with result.products: two projections of
     * one result are two chances to be wrong, and the one we persist is the one
     * the customer sees. We never merge them and never pick a winner.
     */
    private function validated($body): ?array
    {
        if (! is_array($body) || array_diff(array_keys($body), self::TERMINAL_KEYS) !== []) {
            return null;
        }
        if (($body['schema_version'] ?? null) !== LiveShoppingEngine::SCHEMA_VERSION) {
            return null;
        }

        $ids = [];
        foreach (['delivery_id', 'session_id', 'conversation_id'] as $field) {
            $value = $body[$field] ?? null;
            if (! is_string($value) || $value === '' || strlen($value) > 200
                || ! preg_match('/^[A-Za-z0-9_.:-]+$/', $value)) {
                return null;
            }
            $ids[$field] = $value;
        }

        // Positive and within a sane integer bound. 0 is not a terminal sequence.
        $seq = $body['terminal_seq'] ?? null;
        if (! is_int($seq) || $seq < 1 || $seq > PHP_INT_MAX) {
            return null;
        }

        $occurredAt = $body['occurred_at'] ?? null;
        if (! is_string($occurredAt) || strtotime($occurredAt) === false) {
            return null;
        }

        $result = $body['result'] ?? null;
        if (! is_array($result) || array_diff(array_keys($result), ['outcome', 'products', 'error_code']) !== []) {
            return null;
        }
        if (! in_array($result['outcome'] ?? null, ['completed', 'failed', 'cancelled'], true)) {
            return null;
        }

        $errorCode = $result['error_code'] ?? null;
        if ($errorCode !== null && (! is_string($errorCode) || strlen($errorCode) > 120
            || ! preg_match('/^[a-z0-9_.-]+$/i', $errorCode))) {
            return null;
        }

        $part = $body['assistant_part'] ?? null;
        if (! is_array($part) || array_diff(array_keys($part), ['type', 'state', 'output']) !== []) {
            return null;
        }
        if (($part['type'] ?? null) !== 'tool-live_results'
            || ($part['state'] ?? null) !== 'output-available'
            || ! is_array($part['output'] ?? null)
            || array_diff(array_keys($part['output']), ['products', 'caveat']) !== []) {
            return null;
        }
        // Optional caveat: a CLOSED vocabulary the UI renders as a visible
        // "misses a requested constraint" label. Anything else is not ours.
        $caveat = $part['output']['caveat'] ?? null;
        if ($caveat !== null && $caveat !== 'partial_match') {
            return null;
        }

        $resultProducts = $result['products'] ?? [];
        $partProducts   = $part['output']['products'] ?? [];
        if (! is_array($resultProducts) || ! is_array($partProducts)) {
            return null;
        }
        if (array_keys($resultProducts) !== array_keys(array_values($resultProducts))
            || array_keys($partProducts) !== array_keys(array_values($partProducts))) {
            return null;   // objects masquerading as lists
        }
        if (json_encode($resultProducts) !== json_encode($partProducts)) {
            return null;   // divergent — persist neither
        }

        // Strict: any malformed product rejects the whole delivery rather than
        // silently shrinking the list the customer is shown.
        $products = ProductV1::boundStrict($resultProducts);
        if ($products === null) {
            return null;
        }

        return [
            'delivery_id'     => $ids['delivery_id'],
            'session_id'      => $ids['session_id'],
            'conversation_id' => $ids['conversation_id'],
            'terminal_seq'    => $seq,
            'occurred_at'     => $occurredAt,
            'result'          => ['outcome' => $result['outcome'], 'error_code' => $errorCode],
            // The EXACT frozen part shape. No toolCallId, no input: those were
            // never in the contract, and inventing fields here would mean the
            // persisted part is not the one that was agreed and verified.
            'assistant_part'  => [
                'type'   => 'tool-live_results',
                'state'  => 'output-available',
                'output' => array_merge(['products' => $products], $caveat === null ? [] : ['caveat' => $caveat]),
            ],
        ];
    }
}
