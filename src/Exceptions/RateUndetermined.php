<?php

namespace Goldnead\Invoices\Exceptions;

use Goldnead\StatamicPayments\Models\Payment;

/**
 * No rule said what tax applies, so no invoice was written.
 *
 * The alternative would be a fallback to the standard rate, and that is exactly
 * the failure this addon exists to avoid: a wrong rate on a tax document looks
 * like an answer. It is wrong quietly, it is signed, and it is handed to a
 * customer.
 *
 * So the payment stands — the money moved, nothing about that is in doubt — and
 * the invoice waits for somebody to say which rule applies. `invoices:pending`
 * lists what is waiting.
 */
class RateUndetermined extends InvoiceNotWritten
{
    /** @param  list<array{product: string|null, code: string|null, reason: string}>  $lines */
    public function __construct(
        public readonly Payment $payment,
        public readonly array $lines,
    ) {
        $handles = implode(', ', array_filter(array_column($lines, 'product'))) ?: '—';

        parent::__construct(
            "No tax rule matched for payment {$payment->getKey()} ({$handles}). "
            .'The payment stands; the invoice was not written, because guessing a rate '
            .'would put a wrong figure on a tax document.'
        );
    }
}
