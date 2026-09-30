<?php

namespace App\Services;

/** The final invoice reached the customer in Stripe but saving it locally failed; never regenerate it. */
class InPersonInvoiceSentButUnrecorded extends \RuntimeException
{
    public function __construct(public string $invoiceId)
    {
        parent::__construct("Final invoice $invoiceId was sent but not recorded");
    }
}
