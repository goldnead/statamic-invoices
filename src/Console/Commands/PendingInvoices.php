<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\Invoices\Exceptions\RateUndetermined;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Console\Command;

/**
 * Paid payments that have no invoice, and why.
 *
 * The two reasons are different and the difference matters: an invoice that was
 * never attempted (the addon was installed later) needs a run, while one that
 * could not be written needs a decision about a tax rule. Showing both in one
 * list without saying which is which would hide the second behind the first.
 */
class PendingInvoices extends Command
{
    protected $signature = 'invoices:pending {--write : Die schreiben, die sich schreiben lassen}';

    protected $description = 'Paid payments without an invoice, and what is missing.';

    public function handle(InvoiceWriter $writer): int
    {
        $ohne = Payment::query()
            ->where('status', Payment::STATUS_PAID)
            ->whereNotIn('id', Invoice::query()->whereNotNull('payment_id')->pluck('payment_id'))
            ->with('items')
            ->orderBy('id')
            ->get();

        if ($ohne->isEmpty()) {
            $this->components->info('Jede bezahlte Zahlung hat ihre Rechnung.');

            return self::SUCCESS;
        }

        $geschrieben = 0;
        $offen = [];

        foreach ($ohne as $zahlung) {
            if (! $this->option('write')) {
                $offen[] = [$zahlung->id, $zahlung->product, $zahlung->country ?: '—', '(nicht versucht)'];

                continue;
            }

            try {
                $writer->forPayment($zahlung);
                $geschrieben++;
            } catch (RateUndetermined $e) {
                $offen[] = [
                    $zahlung->id,
                    $zahlung->product,
                    $zahlung->country ?: '—',
                    $e->lines[0]['reason'] ?? 'keine Regel gefunden',
                ];
            }
        }

        if ($geschrieben > 0) {
            $this->components->info($geschrieben.' Rechnungen geschrieben.');
        }

        if ($offen !== []) {
            $this->newLine();
            $this->table(['Zahlung', 'Produkt', 'Land', 'Was fehlt'], $offen);
            $this->newLine();
            $this->components->warn(
                count($offen).' Zahlungen ohne Rechnung. Ein fehlender Satz wird nicht geraten — '
                .'die Regel gehört in config/invoices.php, dann diesen Befehl mit --write erneut.'
            );
        }

        return self::SUCCESS;
    }
}
