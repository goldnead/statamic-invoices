<?php

namespace Goldnead\Invoices\Listeners;

use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Events\InvoiceIssued;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the invoice the moment it exists.
 *
 * On the event, not on a schedule. A cron would be a second thing that decides
 * *whether* a document should exist — it would have to ask which invoices still
 * need sending, and the honest answer to that lives on an immutable row that
 * cannot hold it. `InvoiceIssued` fires once, after the transaction, for
 * exactly the documents that were written.
 *
 * Autoloaded by `AddonServiceProvider` off the first parameter type below.
 */
class DeliverInvoiceToBuyer
{
    public function __construct(protected InvoiceDelivery $delivery) {}

    public function handle(InvoiceIssued $event): void
    {
        if (! config('invoices.delivery.enabled', true)) {
            return;
        }

        try {
            $this->delivery->send($event->invoice);
        } catch (Throwable $e) {
            // Loud, but not fatal — the same rule the writer follows. This
            // listener hangs off a chain that starts at a provider's webhook: an
            // exception escaping here releases the fulfilment claim in
            // `statamic-payments`, the provider redelivers, and the buyer's
            // access is revoked and regranted over a mail server that was busy.
            // The invoice exists and is not going anywhere.
            Log::error('invoices: '.$event->invoice->number.' could not be sent: '.$e->getMessage(), [
                'invoice_id' => $event->invoice->getKey(),
                'exception' => $e::class,
            ]);
        }
    }
}
