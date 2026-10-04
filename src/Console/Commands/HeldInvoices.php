<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\Invoices\Models\DeliveryRecord;
use Illuminate\Console\Command;

/**
 * Invoices that were written and did not go out: held back on purpose, stuck
 * in "sending" because a process died between the claim and the mail, failed
 * and waiting for `invoices:retry`, or `failed_final` (out of attempts).
 *
 * All but `failed` need a person. `invoices:release {number}` sends one as it is.
 */
class HeldInvoices extends Command
{
    protected $signature = 'invoices:held';

    protected $description = 'Invoices that were held back or are stuck in sending.';

    public function handle(): int
    {
        $rows = DeliveryRecord::query()
            ->whereIn('status', [
                DeliveryRecord::STATUS_HELD,
                DeliveryRecord::STATUS_SENDING,
                DeliveryRecord::STATUS_FAILED,
                DeliveryRecord::STATUS_FAILED_FINAL,
            ])
            ->with('invoice')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->components->info('No invoice is held back.');

            return self::SUCCESS;
        }

        $this->table(
            ['Number', 'Status', 'Reason', 'Recipient', 'Since'],
            $rows->map(fn (DeliveryRecord $row) => [
                (string) $row->invoice?->number,
                $row->status.($row->attempts > 0 ? " ({$row->attempts})" : ''),
                (string) ($row->reason ?? $row->last_error),
                (string) $row->recipient,
                (string) $row->updated_at,
            ])->all(),
        );

        $this->line('Send one as it is: php artisan invoices:release <number>');

        return self::SUCCESS;
    }
}
