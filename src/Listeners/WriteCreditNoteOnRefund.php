<?php

namespace Goldnead\Invoices\Listeners;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\StatamicPayments\Events\PaymentRefunded;

/**
 * A refund is a second document, never a correction.
 *
 * Only on a full refund for now: a partial one needs somebody to say which
 * lines came back, and guessing that would put a wrong figure on a tax
 * document. Recorded either way by the payments addon; the paperwork for a
 * partial refund is a person's decision.
 */
class WriteCreditNoteOnRefund
{
    public function __construct(protected InvoiceWriter $writer) {}

    public function handle(PaymentRefunded $event): void
    {
        if (! $event->isFull || ! config('invoices.auto_issue', true)) {
            return;
        }

        $this->writer->creditNoteFor($event->payment);
    }
}
