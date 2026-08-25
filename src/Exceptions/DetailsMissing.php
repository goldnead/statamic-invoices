<?php

namespace Goldnead\Invoices\Exceptions;

use Goldnead\StatamicPayments\Models\Payment;

/**
 * A mandatory detail is missing, so the document would not be an invoice.
 *
 * § 14 Abs. 4 UStG lists what has to be on one. The two this addon can check
 * are the sender's own details and, above the small-amount threshold, the
 * recipient's full address — and it checks them **before** writing, because a
 * written invoice cannot be corrected, only reversed and reissued.
 *
 * Below €250 gross, § 33 UStDV allows a Kleinbetragsrechnung: no recipient
 * address, no recipient tax number. That is the ordinary case for a digital
 * product, so demanding an address there would refuse invoices the law is
 * perfectly happy with.
 */
class DetailsMissing extends InvoiceNotWritten
{
    /** @param  list<string>  $missing */
    public function __construct(
        public readonly Payment $payment,
        public readonly array $missing,
    ) {
        parent::__construct(
            'The invoice for payment '.$payment->getKey().' was not written: '
            .implode(', ', $missing).'. § 14 UStG requires these, and an invoice cannot be '
            .'corrected once issued — only reversed and reissued.'
        );
    }
}
