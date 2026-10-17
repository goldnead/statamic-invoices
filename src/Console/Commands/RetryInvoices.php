<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Invoices\Console\Concerns\RunsPerBrand;
use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Models\DeliveryRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tries a failed invoice mail again. Scheduled every five minutes by the addon.
 *
 * Picks only rows in `failed`, never `sent`, `sending`, `held` or
 * `failed_final`, and only once the gap has passed: `after_minutes` doubled
 * for every attempt already made. Whether it may send at all is decided by
 * {@see InvoiceDelivery}, which claims the row atomically; two runs at once
 * send one mail.
 */
class RetryInvoices extends Command
{
    use RunsPerBrand;

    protected $signature = 'invoices:retry
        {--brand= : Only this brand (handle or ID)}';

    protected $description = 'Try invoice mails that failed again (bounded, with backoff).';

    /**
     * Once per brand on a multi-brand host: whether delivery is on, the
     * subject, the file name and the retry gap are the brand's saved settings.
     * The rows are those of invoices of that brand.
     */
    public function handle(InvoiceDelivery $delivery): int
    {
        return $this->forEveryBrand(fn (?Brand $brand) => $this->process($delivery, $brand));
    }

    protected function process(InvoiceDelivery $delivery, ?Brand $brand): int
    {
        if (! config('invoices.delivery.enabled', true)) {
            return self::SUCCESS;
        }

        $rows = DeliveryRecord::query()
            ->where('status', DeliveryRecord::STATUS_FAILED)
            ->when($brand !== null, fn ($q) => $q->whereHas(
                'invoice',
                fn ($invoice) => $this->forBrand($invoice, $brand),
            ))
            ->with('invoice.items')
            ->orderBy('id')
            ->get()
            ->filter(fn (DeliveryRecord $row) => $row->invoice !== null && $row->isDueForRetry());

        $sent = 0;

        foreach ($rows as $row) {
            try {
                if ($delivery->send($row->invoice)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                // The delivery has counted the attempt (and logs the final one at
                // error); the cause is kept on the row. Here it is only said that
                // a retry ran, with the number and nothing else about the buyer.
                Log::warning('invoices: retry failed', [
                    'number' => $row->invoice->number,
                    'attempt' => $row->attempts + 1,
                ]);
                $this->components->warn($row->invoice->number.' failed again: '.$e->getMessage());
            }
        }

        if ($rows->isNotEmpty()) {
            $this->components->info("{$sent} of {$rows->count()} sent.");
        }

        return self::SUCCESS;
    }
}
