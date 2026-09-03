<?php

namespace App\Services;

use RuntimeException;

/**
 * Anything the engine boundary can go wrong with, in one type, carrying the HTTP
 * status the CONTROLLER should return — never the engine's own status.
 *
 * The message is an internal CODE, not prose, and never reaches a customer.
 * Upstream error text is attacker-influencable; echoing it verbatim would render
 * whatever the engine (or something impersonating it) chose to say. `customer()`
 * maps codes to a small set of stable messages instead.
 */
class LiveShoppingEngineException extends RuntimeException
{
    /** 503 = no usable answer. 422 = the engine answered clearly and refused. */
    public function __construct(string $code, public readonly int $status = 503)
    {
        parent::__construct($code);
    }

    public static function unavailable(string $code): self
    {
        return new self($code, 503);
    }

    public static function refused(string $code): self
    {
        return new self($code, 422);
    }

    /** A stable, non-leaking message for the customer. */
    public function customer(): string
    {
        return match ($this->getMessage()) {
            'not_configured'   => 'live shopping is not configured',
            'store_unsupported' => 'that store is not available for live shopping yet',
            'rate_limited'     => 'the shopping engine is busy, please try again shortly',
            'media_unavailable' => 'the live viewer is unavailable right now',
            default => $this->status === 422
                ? 'the shopping engine could not start this session'
                : 'the shopping engine is unavailable',
        };
    }
}
