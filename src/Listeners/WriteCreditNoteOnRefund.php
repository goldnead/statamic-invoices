<?php

namespace Goldnead\Invoices\Listeners;

use Goldnead\Invoices\Exceptions\InvoiceNotWritten;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\StatamicPayments\Events\PaymentRefunded;
use Illuminate\Support\Facades\Log;

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

        try {
            $this->writer->creditNoteFor($event->payment);
        } catch (InvoiceNotWritten $e) {
            // Wie beim Ausstellen: die Erstattung ist passiert, das Papier
            // fehlt. Eine Ausnahme bis nach oben wuerde den Widerruf des
            // Zugangs mitreissen, der bereits richtig gelaufen ist.
            Log::warning($e->getMessage(), ['payment_id' => $event->payment->getKey()]);
        }
    }
}
