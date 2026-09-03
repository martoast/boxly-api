<?php

namespace App\Support;

/**
 * Canonical signing strings for BOTH directions of the live shopping boundary.
 *
 * They are deliberately DIFFERENT shapes, and both live here so the difference
 * is visible in one place rather than drifting apart in two files:
 *
 *   outbound (Laravel -> engine)   v1\nMETHOD\nPATH\nTIMESTAMP\nNONCE\nBODY_SHA256
 *   inbound  (engine -> Laravel)   v1\nTIMESTAMP\nNONCE\nBODY_SHA256
 *
 * The outbound string binds the METHOD and PATH as well, so a signed body cannot
 * be replayed against a different endpoint. Every element that is checked must be
 * inside the signed string — anything outside it can simply be rewritten by
 * whoever is replaying.
 *
 * Note both sign the HASH of the body rather than the body itself, which is why
 * the content-hash header is not decoration: it is the only thing binding the
 * signed envelope to the bytes actually sent.
 */
class LiveShoppingSignature
{
    /** Maximum accepted clock skew is caller-supplied; this is only the format. */
    public static function outboundCanonical(string $method, string $path, string $timestamp, string $nonce, string $bodyHash): string
    {
        return "v1\n" . strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $bodyHash;
    }

    public static function inboundCanonical(string $timestamp, string $nonce, string $bodyHash): string
    {
        return "v1\n" . $timestamp . "\n" . $nonce . "\n" . $bodyHash;
    }

    public static function sign(string $canonical, string $secret): string
    {
        return 'v1=' . hash_hmac('sha256', $canonical, $secret);
    }

    /** Constant-time: a plain === leaks the secret one byte at a time. */
    public static function matches(string $presented, string $canonical, string $secret): bool
    {
        if (! str_starts_with($presented, 'v1=')) {
            return false;   // absent or unknown prefix is a rejection, never a fallback
        }

        return hash_equals(hash_hmac('sha256', $canonical, $secret), substr($presented, 3));
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function hashBody(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
