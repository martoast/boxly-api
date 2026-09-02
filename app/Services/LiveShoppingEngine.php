<?php

namespace App\Services;

use App\Support\LiveShoppingSignature;
use App\Support\ProductV1;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ONLY thing in this repo that talks to the live shopping engine.
 *
 * It owns the EngineV1 envelope end to end: it signs every outbound request,
 * requires `ok: true` and a known schema_version on every response, and unwraps
 * `data` before anything else sees it. No controller ever touches `ok` or
 * `schema_version`, and this class never renders HTTP.
 *
 * It NEVER invents anything. If the engine is unreachable, or answers without a
 * field we need, or answers about a different session than we asked about, the
 * caller gets an exception and the customer gets an honest 503 — not a
 * fabricated ticket, session id or deadline. A made-up SFU URL or TURN
 * credential renders as a viewer that connects to nothing, which to the customer
 * is indistinguishable from a working session.
 */
class LiveShoppingEngine
{
    public const SCHEMA_VERSION = 1;

    /** Engine catalog cache: brief, success-only, so a restart shows within a minute. */
    public const CATALOG_CACHE_KEY = 'live_shopping_engine.catalog';
    public const CATALOG_CACHE_SECONDS = 60;
    public const STORE_ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    /** The only callback id P1 may send. A literal, never composed from input. */
    public const CALLBACK_ID = 'boxly-p1';

    /**
     * Unset config means OFF, not open. A half-configured deployment behaves
     * exactly like an unconfigured one — never partially live.
     */
    public function configured(): bool
    {
        return $this->baseUrl() !== ''
            && $this->serviceSecret() !== ''
            && $this->serviceKeyId() !== '';
    }

    public function enabled(): bool
    {
        return $this->configured() && (bool) config('services.live_shopping_engine.enabled');
    }

    /**
     * Create a session. Returns the unwrapped, VALIDATED `session` object.
     *
     * The callback destination is not sent: the engine's deployment config maps
     * callback_id to its own canonical HTTPS webhook URL. Laravel never builds
     * or transmits a callback URL, so there is no field a caller could influence
     * and no outbound URL for this repo to get wrong.
     */
    public function createSession(int $localRowId, int $conversationId, string $storeId, string $objective): array
    {
        $data = $this->post('/v1/sessions', [
            'schema_version'  => self::SCHEMA_VERSION,
            'conversation_id' => (string) $conversationId,
            'store_id'        => $storeId,
            'query'           => $objective,
            'callback_id'     => self::CALLBACK_ID,
        ], ['Idempotency-Key' => 'live-shopping-session-' . $localRowId]);

        // The envelope is closed too, not just the session inside it. An extra
        // key out here means the same thing it means in there: we are not
        // speaking the contract we think we are.
        $this->assertClosedKeys($data, ['schema_version', 'session'], 'create_envelope');

        $session = $data['session'] ?? null;
        if (! is_array($session)) {
            throw LiveShoppingEngineException::unavailable('no_session');
        }

        // Closed key set: an unexpected field means we are not speaking the
        // contract we think we are.
        $this->assertClosedKeys($session, [
            'id', 'conversation_id', 'store_id', 'status', 'latest_seq', 'created_at', 'expires_at',
        ], 'session');

        $id = $this->boundedId($session['id'] ?? null, 200);
        if ($id === null) {
            throw LiveShoppingEngineException::unavailable('bad_session_id');
        }

        // "running" and nothing else. An engine that answers `pending` has not
        // durably accepted, and treating that as live is the lie this guards.
        if (($session['status'] ?? null) !== 'running') {
            throw LiveShoppingEngineException::unavailable('not_accepted');
        }

        // Correlation validated, not assumed: a mismatch means we would attach
        // another session's live stream to this customer's thread, and nothing
        // about status or expiry would ever catch it.
        if ((string) ($session['conversation_id'] ?? '') !== (string) $conversationId
            || (string) ($session['store_id'] ?? '') !== $storeId) {
            throw LiveShoppingEngineException::unavailable('correlation_mismatch');
        }

        $latestSeq = $session['latest_seq'] ?? null;
        if (! is_int($latestSeq) || $latestSeq < 0 || $latestSeq > PHP_INT_MAX) {
            throw LiveShoppingEngineException::unavailable('bad_latest_seq');
        }

        if ($this->rfc3339($session['created_at'] ?? null) === null) {
            throw LiveShoppingEngineException::unavailable('bad_created_at');
        }

        // A session with no FUTURE deadline holds the customer's one active slot
        // with nothing for the reconciler to enforce.
        $expiresAt = $this->rfc3339($session['expires_at'] ?? null);
        if ($expiresAt === null || $expiresAt <= time()) {
            throw LiveShoppingEngineException::unavailable('bad_deadline');
        }

        return [
            'id'              => $id,
            'conversation_id' => (string) $session['conversation_id'],
            'store_id'        => (string) $session['store_id'],
            'status'          => 'running',
            'latest_seq'      => $latestSeq,
            'expires_at'      => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
        ];
    }

    /**
     * Mint a viewer ticket. Engine round-trip on EVERY request; nothing is
     * persisted and nothing is invented.
     */
    public function viewerTicket(string $engineSessionId, int $userId): array
    {
        // The id came from the engine, but it lands in a URL path: encode it so
        // a hostile or merely odd id cannot alter which endpoint we call.
        $path = '/v1/sessions/' . rawurlencode($engineSessionId) . '/viewer-tickets';

        $data = $this->post($path, [
            'schema_version' => self::SCHEMA_VERSION,
            'user_id'        => (string) $userId,
            'scopes'         => ['events:read', 'media:read'],   // P1 is view-only
        ]);

        $this->assertClosedKeys($data, [
            'schema_version', 'ticket', 'expires_at', 'sse_url', 'media_available', 'whep_url', 'ice_servers',
        ], 'ticket');

        $ticket = $this->boundedId($data['ticket'] ?? null, 4096);
        if ($ticket === null) {
            throw LiveShoppingEngineException::unavailable('bad_ticket');
        }

        // >60s is the engine misbehaving. Silently shortening it would hide that,
        // so this is an invalid response rather than something to clamp.
        $expiresAt = $this->rfc3339($data['expires_at'] ?? null);
        if ($expiresAt === null || $expiresAt <= time() || $expiresAt > time() + 60) {
            throw LiveShoppingEngineException::unavailable('bad_ticket_lifetime');
        }

        // media_available is a REQUIRED boolean and the media fields must agree
        // with it exactly: false ↔ whep_url null AND ice_servers []. Optional
        // media never weakens the events contract — a ticket always carries a
        // working sse_url; any inconsistent combination is a contract violation,
        // never something to coerce.
        foreach (['media_available', 'whep_url', 'ice_servers'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw LiveShoppingEngineException::unavailable('bad_media_flag');
            }
        }
        $mediaAvailable = $data['media_available'];
        if (! is_bool($mediaAvailable)) {
            throw LiveShoppingEngineException::unavailable('bad_media_flag');
        }
        if ($mediaAvailable) {
            $whepUrl    = $this->publicHttpsUrl($data['whep_url']);
            $iceServers = $this->iceServers($data['ice_servers']);
        } elseif ($data['whep_url'] !== null || $data['ice_servers'] !== []) {
            throw LiveShoppingEngineException::unavailable('bad_media_flag');
        } else {
            $whepUrl    = null;
            $iceServers = [];
        }

        return [
            'ticket'          => $ticket,
            'expires_at'      => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
            'sse_url'         => $this->publicHttpsUrl($data['sse_url'] ?? null),
            'media_available' => $mediaAvailable,
            'whep_url'        => $whepUrl,
            'ice_servers'     => $iceServers,
        ];
    }

    /**
     * Read the engine's durable status for reconciliation. This is deliberately
     * separate from createSession(): a terminal status is valid here, while a
     * create response must be running.
     */
    public function sessionStatus(string $engineSessionId): array
    {
        $data = $this->get('/v1/sessions/' . rawurlencode($engineSessionId));
        $this->assertClosedKeys($data, ['schema_version', 'session'], 'status_envelope');
        $session = $data['session'] ?? null;
        if (! is_array($session)) {
            throw LiveShoppingEngineException::unavailable('no_session');
        }
        $this->assertClosedKeys($session, [
            'id', 'conversation_id', 'store_id', 'status', 'latest_seq', 'media_status',
            'terminal_result', 'created_at', 'updated_at', 'expires_at',
        ], 'status_session');
        $id = $this->boundedId($session['id'] ?? null, 200);
        $status = $session['status'] ?? null;
        $latestSeq = $session['latest_seq'] ?? null;
        if ($id === null || $id !== $engineSessionId
            || ! is_string($status) || ! in_array($status, [
                'created', 'starting', 'running', 'cancelling', 'completed', 'failed', 'cancelled',
            ], true)
            || ! is_int($latestSeq) || $latestSeq < 0) {
            throw LiveShoppingEngineException::unavailable('bad_status');
        }
        $terminal = $session['terminal_result'] ?? null;
        if ($terminal !== null && (! is_array($terminal)
            || array_diff(array_keys($terminal), ['outcome', 'products', 'error_code']) !== []
            || ! in_array($terminal['outcome'] ?? null, ['completed', 'failed', 'cancelled'], true))) {
            throw LiveShoppingEngineException::unavailable('bad_terminal_result');
        }
        if (in_array($status, ['completed', 'failed', 'cancelled'], true) && ! is_array($terminal)) {
            throw LiveShoppingEngineException::unavailable('missing_terminal_result');
        }
        $products = [];
        if (is_array($terminal)) {
            if (! array_key_exists('products', $terminal) || ! is_array($terminal['products'])) {
                throw LiveShoppingEngineException::unavailable('bad_terminal_result');
            }
            $products = ProductV1::boundStrict($terminal['products']);
            if ($products === null || ($terminal['error_code'] ?? null) !== null
                && (! is_string($terminal['error_code']) || strlen($terminal['error_code']) > 120
                    || ! preg_match('/^[a-z0-9_.-]+$/i', $terminal['error_code']))) {
                throw LiveShoppingEngineException::unavailable('bad_terminal_result');
            }
            if (($terminal['outcome'] ?? null) !== $status) {
                throw LiveShoppingEngineException::unavailable('bad_terminal_result');
            }
        }

        return [
            'id' => $id,
            'conversation_id' => (string) ($session['conversation_id'] ?? ''),
            'store_id' => (string) ($session['store_id'] ?? ''),
            'status' => $status,
            'latest_seq' => $latestSeq,
            'error_code' => is_array($terminal) && is_string($terminal['error_code'] ?? null)
                ? $terminal['error_code'] : null,
            'products' => $products,
        ];
    }

    /**
     * Best-effort cancel, used when we lose the race to persist an accepted
     * session (the reconciler expired the row while the create was in flight).
     *
     * Never throws: the caller is already returning an error, and a session the
     * engine keeps alive is bounded by the engine's own deadline anyway. What
     * matters is that we do not resurrect a local row outside the one-active
     * constraint.
     */
    public function cancelSessionQuietly(string $engineSessionId): void
    {
        try {
            $this->post('/v1/sessions/' . rawurlencode($engineSessionId) . '/cancel', [
                'schema_version' => self::SCHEMA_VERSION,
                'reason'         => 'local_state_lost',
            ]);
        } catch (Throwable $e) {
            Log::warning('live-shopping best-effort cancel failed', [
                'engine_session_id' => $engineSessionId,
            ]);
        }
    }

    /**
     * One signed POST, the whole envelope contract. Returns the unwrapped `data`.
     *
     * The JSON is serialized ONCE and those exact bytes are both hashed and
     * sent. Re-encoding between signing and sending is the classic way to ship a
     * signature that never verifies.
     */
    private function post(string $path, array $body, array $extraHeaders = []): array
    {
        if (! $this->configured()) {
            throw LiveShoppingEngineException::unavailable('not_configured');
        }

        $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $nonce = LiveShoppingSignature::nonce();
        $bodyHash = LiveShoppingSignature::hashBody($raw);

        $headers = array_merge([
            'Accept'                 => 'application/json',
            'Content-Type'           => 'application/json',
            'X-Boxly-Key-Id'         => $this->serviceKeyId(),
            'X-Boxly-Timestamp'      => $timestamp,
            'X-Boxly-Nonce'          => $nonce,
            'X-Boxly-Content-SHA256' => $bodyHash,
            'X-Boxly-Signature'      => LiveShoppingSignature::sign(
                LiveShoppingSignature::outboundCanonical('POST', $path, $timestamp, $nonce, $bodyHash),
                $this->serviceSecret(),
            ),
        ], $extraHeaders);

        try {
            $response = Http::withHeaders($headers)
                // The chat is interactive: a hung engine must never hold a web
                // worker open waiting for it.
                ->timeout($this->clamp((int) config('services.live_shopping_engine.timeout', 8), 1, 30))
                ->connectTimeout(5)
                ->withBody($raw, 'application/json')
                ->post($this->baseUrl() . $path);
        } catch (Throwable $e) {
            Log::warning('live-shopping engine unreachable', ['path' => $path, 'error' => $e->getMessage()]);
            throw LiveShoppingEngineException::unavailable('unreachable');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw LiveShoppingEngineException::unavailable('unreadable');
        }

        // Order matters: a refusal carries the engine's own reason and may
        // legitimately arrive without a data envelope, so checking the version
        // first would replace that reason with a misleading version complaint.
        if (($json['ok'] ?? false) !== true) {
            // Upstream text is logged, never echoed: it is attacker-influencable
            // and would otherwise be rendered to a customer verbatim.
            Log::warning('live-shopping engine refused', [
                'path'   => $path,
                'status' => $response->status(),
                'code'   => $json['error']['code'] ?? null,
            ]);

            // Preserve the one explicitly retryable viewer refusal without
            // proxying arbitrary upstream statuses, flags or prose.
            if (($json['error']['code'] ?? null) === 'media_unavailable'
                && $response->status() === 503
                && ($json['error']['retryable'] ?? null) === true) {
                throw LiveShoppingEngineException::unavailable('media_unavailable');
            }

            // The one refusal the customer can act on keeps its meaning: the
            // engine does not know this store. Every other upstream code stays
            // an opaque refusal.
            $code = (string) ($json['error']['code'] ?? 'refused');
            if ($code === 'unknown_store') {
                throw LiveShoppingEngineException::refused('store_unsupported');
            }

            throw LiveShoppingEngineException::refused($code);
        }

        if ($response->failed()) {
            throw LiveShoppingEngineException::unavailable('unreachable');
        }

        $data = $json['data'] ?? null;
        if (! is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw LiveShoppingEngineException::unavailable('unsupported_schema');
        }

        return $data;
    }

    /** Signed GET with an empty body; status reconciliation must not send JSON. */
    /**
     * The stores the engine can open live — the ONLY routing source. Boxly's
     * assistant offers exactly this list, so a store the engine would refuse
     * with unknown_store is never proposed. Validated to the closed shape
     * {id, name}; cached briefly on success only (a failure is never cached).
     *
     * @return list<array{id: string, name: string}>
     */
    public function catalog(): array
    {
        $cached = Cache::get(self::CATALOG_CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $data = $this->get('/v1/catalog');
        $stores = [];
        foreach ((array) ($data['stores'] ?? []) as $store) {
            if (! is_array($store)
                || ! is_string($store['id'] ?? null) || ! preg_match(self::STORE_ID_PATTERN, $store['id'])
                || ! is_string($store['name'] ?? null) || trim($store['name']) === '' || strlen($store['name']) > 120) {
                Log::warning('live-shopping engine catalog entry rejected');
                throw LiveShoppingEngineException::unavailable('unreadable');
            }
            $stores[$store['id']] = ['id' => $store['id'], 'name' => trim($store['name'])];
        }
        if ($stores === []) {
            throw LiveShoppingEngineException::unavailable('unreadable');
        }
        $list = array_values($stores);
        Cache::put(self::CATALOG_CACHE_KEY, $list, self::CATALOG_CACHE_SECONDS);

        return $list;
    }

    private function get(string $path): array
    {
        if (! $this->configured()) {
            throw LiveShoppingEngineException::unavailable('not_configured');
        }
        $timestamp = (string) time();
        $nonce = LiveShoppingSignature::nonce();
        $bodyHash = LiveShoppingSignature::hashBody('');
        $headers = [
            'Accept' => 'application/json',
            'X-Boxly-Key-Id' => $this->serviceKeyId(),
            'X-Boxly-Timestamp' => $timestamp,
            'X-Boxly-Nonce' => $nonce,
            'X-Boxly-Content-SHA256' => $bodyHash,
            'X-Boxly-Signature' => LiveShoppingSignature::sign(
                LiveShoppingSignature::outboundCanonical('GET', $path, $timestamp, $nonce, $bodyHash),
                $this->serviceSecret(),
            ),
        ];
        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->clamp((int) config('services.live_shopping_engine.timeout', 8), 1, 30))
                ->connectTimeout(5)->get($this->baseUrl() . $path);
        } catch (Throwable $e) {
            Log::warning('live-shopping engine status unreachable', ['path' => $path]);
            throw LiveShoppingEngineException::unavailable('unreachable');
        }
        $json = $response->json();
        if (! is_array($json) || ($json['ok'] ?? false) !== true || $response->failed()) {
            throw LiveShoppingEngineException::unavailable('unreadable');
        }
        $data = $json['data'] ?? null;
        if (! is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw LiveShoppingEngineException::unavailable('unsupported_schema');
        }
        return $data;
    }

    /** ---- validation helpers ------------------------------------------- */

    private function assertClosedKeys(array $value, array $allowed, string $what): void
    {
        $unexpected = array_diff(array_keys($value), $allowed);
        if ($unexpected !== []) {
            Log::warning('live-shopping engine sent unexpected keys', [
                'what' => $what, 'keys' => array_values($unexpected),
            ]);

            throw LiveShoppingEngineException::unavailable('unexpected_fields');
        }
    }

    private function boundedId($value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max) {
            return null;
        }
        // No control characters: these end up in URLs, logs and JSON.
        return preg_match('/[\x00-\x1F\x7F]/', $value) ? null : $value;
    }

    /** RFC3339 timestamp -> unix seconds, or null. */
    private function rfc3339($value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }

    /** A public https URL with no credentials, query or fragment. */
    private function publicHttpsUrl($value): string
    {
        if (! is_string($value) || strlen($value) > 2000) {
            throw LiveShoppingEngineException::unavailable('bad_media_url');
        }

        $parts = parse_url($value);
        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw LiveShoppingEngineException::unavailable('bad_media_url');
        }

        return $value;
    }

    /**
     * ICE servers are handed straight to a browser's RTCPeerConnection, so the
     * shape and schemes are bounded rather than trusted.
     */
    private function iceServers($value): array
    {
        if (! is_array($value) || $value === [] || count($value) > 8) {
            throw LiveShoppingEngineException::unavailable('bad_ice_servers');
        }

        $out = [];
        foreach ($value as $server) {
            if (! is_array($server)) {
                throw LiveShoppingEngineException::unavailable('bad_ice_servers');
            }
            $this->assertClosedKeys($server, ['urls', 'username', 'credential'], 'ice_server');

            $urls = $server['urls'] ?? null;
            $urls = is_string($urls) ? [$urls] : $urls;
            if (! is_array($urls) || $urls === [] || count($urls) > 8) {
                throw LiveShoppingEngineException::unavailable('bad_ice_servers');
            }

            foreach ($urls as $url) {
                if (! is_string($url) || strlen($url) > 500
                    || ! preg_match('/^(stun|stuns|turn|turns):[A-Za-z0-9.\-]+(:\d{1,5})?(\?transport=(udp|tcp))?$/', $url)) {
                    throw LiveShoppingEngineException::unavailable('bad_ice_servers');
                }
            }

            $entry = ['urls' => array_values($urls)];
            foreach (['username', 'credential'] as $field) {
                if (isset($server[$field])) {
                    if (! is_string($server[$field]) || strlen($server[$field]) > 500) {
                        throw LiveShoppingEngineException::unavailable('bad_ice_servers');
                    }
                    $entry[$field] = $server[$field];
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Canonical https origin, no credentials, query or fragment.
     *
     * http is tolerated only outside production, so a local engine on
     * http://localhost:9000 still works while production cannot silently ship
     * plaintext service credentials.
     */
    private function baseUrl(): string
    {
        $raw = trim((string) config('services.live_shopping_engine.base_url', ''));
        if ($raw === '') {
            return '';
        }

        $url = rtrim($raw, '/');
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }

        $scheme = $parts['scheme'] ?? '';
        $allowPlaintext = ! app()->environment('production');
        if ($scheme !== 'https' && ! ($allowPlaintext && $scheme === 'http')) {
            return '';
        }

        return $url;
    }

    private function serviceSecret(): string
    {
        return (string) config('services.live_shopping_engine.service_secret', '');
    }

    private function serviceKeyId(): string
    {
        return (string) config('services.live_shopping_engine.service_key_id', '');
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
