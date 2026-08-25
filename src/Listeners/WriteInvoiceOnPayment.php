<?php

namespace Goldnead\Invoices\Listeners;

use Goldnead\Invoices\Exceptions\RateUndetermined;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Illuminate\Support\Facades\Log;

/** Autoloaded by `AddonServiceProvider` off the first parameter type below. */
class WriteInvoiceOnPayment
{
    public function __construct(protected InvoiceWriter $writer) {}

    public function handle(PaymentPaid $event): void
    {
        if (! config('invoices.auto_issue', true)) {
            return;
        }

        try {
            $this->writer->forPayment($event->payment->loadMissing('items'));
        } catch (RateUndetermined $e) {
            // Laut, aber nicht toedlich. Die Zahlung ist erfuellt und der Kunde
            // hat, wofuer er bezahlt hat; was fehlt, ist ein Dokument, das
            // niemand raten darf. Eine Ausnahme bis nach oben wuerde die
            // Erfuellung zurueckrollen und den Anbieter alles noch einmal
            // schicken lassen — fuer ein Problem, das kein Wiederholen loest.
            Log::warning($e->getMessage(), [
                'payment_id' => $event->payment->getKey(),
                'lines' => $e->lines,
            ]);
        }
    }
}
