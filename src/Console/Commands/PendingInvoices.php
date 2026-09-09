<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\Invoices\Exceptions\DetailsMissing;
use Goldnead\Invoices\Exceptions\InvoiceNotWritten;
use Goldnead\Invoices\Exceptions\NumberAlreadyTaken;
use Goldnead\Invoices\Exceptions\RateUndetermined;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Paid payments that have no invoice, and why.
 *
 * The two reasons are different and the difference matters: an invoice that was
 * never attempted (the addon was installed later) needs a run, while one that
 * could not be written needs a decision about a tax rule. Showing both in one
 * list without saying which is which would hide the second behind the first.
 *
 * The exit code, for whoever runs this from cron: `0` when there was nothing to
 * complain about, `1` when `--write` left a payment without its invoice, and `1`
 * for an unexpected error on any run, `--write` or not. Every row runs inside
 * its own error boundary, so one bad payment reports itself and the rest are
 * still tried.
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
        $hinweise = [];
        $gescheitert = false;

        foreach ($ohne as $zahlung) {
            // Eine Fehlergrenze je Zeile, und zwar um alles, was an dieser
            // Zahlung passiert. `InvoiceNotWritten` allein reicht nicht: eine
            // Nummernkollision kam als `UniqueConstraintViolationException`
            // durch, riss den Lauf beim ersten Treffer ab, und die uebrigen
            // Zahlungen wurden nicht einmal versucht. Ein Stapel ist nur so
            // viel wert wie die Zeilen, die nach der kaputten noch drankommen.
            try {
                // Was der Steuerrechner an dieser Zahlung fuer zweifelhaft haelt,
                // steht hier je Zeile — ob die Rechnung nun geschrieben wird oder
                // nicht. Sonst liegt der Hinweis nur im Log, und dorthin schaut beim
                // taeglichen Blick niemand.
                foreach ($this->hinweise($writer, $zahlung) as $hinweis) {
                    $hinweise[] = sprintf(
                        'Zahlung %d, %s: %s',
                        $zahlung->id,
                        $hinweis['product'] ?? '—',
                        $hinweis['note'],
                    );
                }

                if (! $this->option('write')) {
                    $offen[] = [$zahlung->id, $zahlung->product, $zahlung->country ?: '—', '(nicht versucht)'];

                    continue;
                }

                $writer->forPayment($zahlung);
                $geschrieben++;
            } catch (\Throwable $e) {
                // Ein `InvoiceNotWritten` ist ein Befund: er gehoert in die
                // Tabelle und sonst nirgendwohin. Alles andere ist ein Fehler
                // dieses Laufs, und eine Tabellenzeile ist zu wenig fuer ihn —
                // ein Cron wirft die Ausgabe weg, und mit ihr Klasse, Datei und
                // Stacktrace. Also ins Log, mit der Ausnahme selbst.
                if (! $e instanceof InvoiceNotWritten) {
                    Log::error('invoices:pending: '.$e->getMessage(), [
                        'exception' => $e,
                        'payment_id' => $zahlung->id,
                    ]);

                    $gescheitert = true;
                }

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

        if ($hinweise !== []) {
            $this->newLine();
            $this->components->warn('Steuerhinweise (nicht auf der Rechnung, aber an ihr gespeichert):');
            $this->components->bulletList($hinweise);
        }

        // Zwei Wege zu einem Fehlschlag. Mit --write ist jede uebrig gebliebene
        // Zeile einer: ein taeglicher Lauf, der mit 0 endet, waehrend Rechnungen
        // fehlen, ist genau die stille Meldung, vor der dieser Befehl schuetzen
        // soll. Und eine unerwartete Ausnahme zaehlt immer, auch beim blossen
        // Zaehlen ohne --write — die Steuerhinweise werden dort naemlich
        // trotzdem geholt, und ein Programmfehler auf diesem Weg darf nicht als
        // gruener Lauf durchgehen.
        return $gescheitert || ($this->option('write') && $offen !== [])
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * The tax rules' doubts about one payment, or nothing when they cannot even
     * be asked: a product without a `digital` flag throws before any rule runs,
     * and that reason already lands in the table through --write.
     *
     * @return list<array{product: string|null, note: string}>
     */
    protected function hinweise(InvoiceWriter $writer, Payment $zahlung): array
    {
        try {
            return $writer->taxNotesFor($zahlung);
        } catch (InvoiceNotWritten) {
            return [];
        }
    }

    /**
     * The one sentence that says what a person has to decide.
     *
     * Deliberately not the exception message: that one names the payment and
     * repeats the law, both of which are already in the row and the column
     * header. What belongs in the table is the missing piece.
     */
    protected function reason(\Throwable $e): string
    {
        return match (true) {
            $e instanceof RateUndetermined => $e->lines[0]['reason'] ?? 'keine Regel gefunden',
            $e instanceof DetailsMissing => implode(', ', $e->missing),
            $e instanceof NumberAlreadyTaken => 'Nummer der Reihe '.$e->series.' schon vergeben',
            // Was hier landet, hat niemand vorhergesehen. Die Klasse gehoert
            // dazu: die blosse Meldung einer fremden Ausnahme sagt oft nicht,
            // woher sie kam, und der Stacktrace ist weg.
            $e instanceof InvoiceNotWritten => $e->getMessage(),
            default => class_basename($e).': '.$e->getMessage(),
        };
    }
}
