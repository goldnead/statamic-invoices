<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Models\DeliveryRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
    protected $signature = 'invoices:retry';

    protected $description = 'Try invoice mails that failed again (bounded, with backoff).';

    public function handle(InvoiceDelivery $delivery): int
    {
        if (! config('invoices.delivery.enabled', true)) {
            return self::SUCCESS;
        }

        $after = max(1, (int) config('invoices.delivery.retry.after_minutes', 10));

        $rows = DeliveryRecord::query()
            ->where('status', DeliveryRecord::STATUS_FAILED)
            ->with('invoice.items')
            ->orderBy('id')
            ->get()
            ->filter(fn (DeliveryRecord $row) => $row->invoice !== null
                && $row->updated_at !== null
                && $row->updated_at->lte(Carbon::now()->subMinutes($after * (2 ** max(0, $row->attempts - 1)))));

        $sent = 0;

        foreach ($rows as $row) {
            try {
                if ($delivery->send($row->invoice)) {
                    $sent++;
                }
            } catch (Throwable $e) {
                // Already counted and logged by the delivery; the next row goes on.
                $this->components->warn($row->invoice->number.' failed again: '.$e->getMessage());
            }
        }

        if ($rows->isNotEmpty()) {
            $this->components->info("{$sent} of {$rows->count()} sent.");
        }

        return self::SUCCESS;
    }
}
