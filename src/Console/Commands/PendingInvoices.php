<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\Invoices\Exceptions\DetailsMissing;
use Goldnead\Invoices\Exceptions\InvoiceNotWritten;
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
            } catch (InvoiceNotWritten $e) {
                // Alle Gruende, nicht nur der fehlende Steuersatz. Vorher fing
                // diese Schleife allein `RateUndetermined`; eine fehlende
                // Pflichtangabe flog bis nach oben und brach den Lauf ab — die
                // uebrigen Zahlungen wurden dann nicht einmal mehr angesehen,
                // und der eine Grund, den der Befehl nennen sollte, stand als
                // Stacktrace da.
                $offen[] = [
                    $zahlung->id,
                    $zahlung->product,
                    $zahlung->country ?: '—',
                    $this->reason($e),
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
                count($offen).' Zahlungen ohne Rechnung. Weder ein fehlender Steuersatz noch eine '
                .'fehlende Pflichtangabe wird geraten — beides gehört nach config/invoices.php '
                .'beziehungsweise an die Zahlung, dann diesen Befehl mit --write erneut.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * The one sentence that says what a person has to decide.
     *
     * Deliberately not the exception message: that one names the payment and
     * repeats the law, both of which are already in the row and the column
     * header. What belongs in the table is the missing piece.
     */
    protected function reason(InvoiceNotWritten $e): string
    {
        return match (true) {
            $e instanceof RateUndetermined => $e->lines[0]['reason'] ?? 'keine Regel gefunden',
            $e instanceof DetailsMissing => implode(', ', $e->missing),
            default => $e->getMessage(),
        };
    }
}
