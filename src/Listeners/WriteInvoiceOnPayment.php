<?php

namespace Goldnead\Invoices\Listeners;

use Goldnead\Invoices\Exceptions\InvoiceNotWritten;
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
        } catch (InvoiceNotWritten $e) {
            // Laut, aber nicht toedlich. Die Zahlung ist erfuellt und der Kunde
            // hat, wofuer er bezahlt hat; was fehlt, ist ein Dokument, das
            // niemand raten darf. Eine Ausnahme bis nach oben wuerde die
            // Erfuellung zurueckrollen und den Anbieter alles noch einmal
            // schicken lassen — fuer ein Problem, das kein Wiederholen loest.
            // Die Ausnahme selbst gehoert dazu, nicht nur ihr Satz. Bei einer
            // Nummernkollision haengt die urspruengliche Datenbankmeldung als
            // `previous` daran, und die nennt den verletzten Index — der
            // einzige Beleg dafuer, dass die Diagnose in der Meldung stimmt.
            Log::warning($e->getMessage(), [
                'exception' => $e,
                'payment_id' => $event->payment->getKey(),
                'lines' => $e instanceof RateUndetermined ? $e->lines : null,
            ]);
        }
    }
}
