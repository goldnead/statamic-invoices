<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Invoices\Console\Concerns\RunsPerBrand;
use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Models\DeliveryRecord;
use Goldnead\Invoices\Models\Invoice;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sends a held (or stuck) invoice as it is, after a person has looked at it.
 *
 * The other way out of a hold, cancelling with a credit note and writing the
 * invoice again, needs no command here. This is for "the zero is right after
 * all". It is logged, so the decision can be found later.
 */
class ReleaseInvoice extends Command
{
    use RunsPerBrand;

    protected $signature = 'invoices:release {number : Die Rechnungsnummer}';

    protected $description = 'Send a held invoice as it is.';

    /**
     * The send runs as the invoice's brand, not as nobody: subject, file name
     * and the rest of the delivery settings are that brand's saved ones. The
     * number is unique across brands, so it names exactly one invoice.
     */
    public function handle(InvoiceDelivery $delivery): int
    {
        $number = (string) $this->argument('number');
        $invoice = Invoice::query()->with('items')->where('number', $number)->first();

        if ($invoice === null) {
            $this->components->error("There is no invoice {$number}.");

            return self::FAILURE;
        }

        // brand_id 0 (written before multi-brand was switched on) belongs to the
        // default brand, as in forBrand(); otherwise the send would use package values.
        $brand = $this->multiBrandActive()
            ? (Brand::query()->find($invoice->brand_id) ?? ((int) $invoice->brand_id === 0 ? Brand::default() : null))
            : null;

        return $brand !== null
            ? (int) BrandContext::runFor($brand, fn () => $this->release($delivery, $invoice))
            : $this->release($delivery, $invoice);
    }

    private function release(InvoiceDelivery $delivery, Invoice $invoice): int
    {
        $number = (string) $invoice->number;

        $status = DeliveryRecord::query()->where('invoice_id', $invoice->getKey())->value('status');

        if ($status === DeliveryRecord::STATUS_SENT) {
            $this->components->error("{$number} was already sent. Nothing was done.");

            return self::FAILURE;
        }

        if ($status === null) {
            $this->components->error("{$number} is not held. Nothing was done.");

            return self::FAILURE;
        }

        try {
            $sent = $delivery->release($invoice);
        } catch (Throwable $e) {
            $this->components->error("{$number} could not be sent: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $sent) {
            $this->components->error("{$number} was released but not sent; the log says why.");

            return self::FAILURE;
        }

        $this->components->info("{$number} was sent to {$invoice->buyer_email}.");

        return self::SUCCESS;
    }
}
