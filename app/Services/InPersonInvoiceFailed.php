<?php

namespace App\Services;

/** A Stripe failure while building a final invoice, before it was sent; invoiceId is the orphan to discard (null if none was created). */
class InPersonInvoiceFailed extends \RuntimeException
{
    public function __construct(public ?string $invoiceId, ?\Throwable $previous = null)
    {
        parent::__construct($previous?->getMessage() ?? 'in-person invoice failed', 0, $previous);
    }
}
