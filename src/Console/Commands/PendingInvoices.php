<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Invoices\Console\Concerns\RunsPerBrand;
use Goldnead\Invoices\Exceptions\DetailsMissing;
use Goldnead\Invoices\Exceptions\InvoiceNotWritten;
use Goldnead\Invoices\Exceptions\NumberAlreadyTaken;
use Goldnead\Invoices\Exceptions\RateUndetermined;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Paid payments that have no invoice, and why.
 *
 * The two reasons are different and the difference matters: an invoice that was
 * never attempted (the addon was installed later) needs a run, while one that
 * could not be written needs a decision about a tax rule. Showing both in one
 * list without saying which is which would hide the second behind the first.
 *
 * The same holds for the second list: a payment that was refunded in full and
 * has an invoice but no credit note (I5). It is found by the writer's own rule
 * (the listener writes a credit note only on a full refund), so a partial refund
 * is never in it. A refund is not a status change, so such a payment still reads
 * `paid`; the list therefore asks the refund columns, not the status.
 *
 * The exit code, for whoever runs this from cron: `0` when there was nothing to
 * complain about, `1` when `--write` left a payment without its invoice or a
 * refund without its credit note, and `1` for an unexpected error on any run,
 * `--write` or not. Every row runs inside its own error boundary, so one bad
 * payment reports itself and the rest are still tried.
 */
class PendingInvoices extends Command
{
    use RunsPerBrand;

    protected $signature = 'invoices:pending
        {--write : Die schreiben, die sich schreiben lassen}
        {--brand= : Nur diese Marke (Handle oder ID)}';

    protected $description = 'Paid payments without an invoice, and what is missing.';

    /**
     * Once per brand on a multi-brand host: the seller and the number series
     * come from the brand's saved settings, and a console run has none of its
     * own. The payments are cut to the brand, or brand B's settings would write
     * brand A's invoice.
     */
    public function handle(InvoiceWriter $writer): int
    {
        return $this->forEveryBrand(fn (?Brand $brand) => $this->process($writer, $brand));
    }

    protected function process(InvoiceWriter $writer, ?Brand $brand): int
    {
        $ohne = $this->forBrand(Payment::query(), $brand)
            ->where('status', Payment::STATUS_PAID)
            ->whereNotIn('id', Invoice::query()->whereNotNull('payment_id')->pluck('payment_id'))
            ->with('items')
            ->orderBy('id')
            ->get();

        $erstattet = $this->erstattetOhneGutschrift($brand);

        if ($ohne->isEmpty() && $erstattet->isEmpty()) {
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

        if ($offen !== []) {
            // Der Cron wirft die Tabelle weg, das Log nicht.
            Log::error('invoices:pending: '.count($offen).' bezahlte Zahlungen ohne Rechnung.', [
                'payment_ids' => array_column($offen, 0),
            ]);
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

        // Zweite Liste, eigene Fehlergrenze je Zeile wie oben. Eine Gutschrift
        // ist nur dort fällig, wo der Listener sie schreibt: bei voller Erstattung.
        $gutschriftGeschrieben = 0;
        $gutschriftOffen = [];

        foreach ($erstattet as $zahlung) {
            try {
                if (! $this->option('write')) {
                    $gutschriftOffen[] = [$zahlung->id, $zahlung->product, $zahlung->country ?: '—', '(nicht versucht)'];

                    continue;
                }

                if ($writer->creditNoteFor($zahlung) !== null) {
                    $gutschriftGeschrieben++;
                }
            } catch (\Throwable $e) {
                // Dieselbe Trennung wie oben: ein Befund in die Tabelle, ein
                // Programmfehler ins Log, mit Ausnahme und Klasse.
                if (! $e instanceof InvoiceNotWritten) {
                    Log::error('invoices:pending: '.$e->getMessage(), [
                        'exception' => $e,
                        'payment_id' => $zahlung->id,
                    ]);

                    $gescheitert = true;
                }

                $gutschriftOffen[] = [
                    $zahlung->id,
                    $zahlung->product,
                    $zahlung->country ?: '—',
                    $this->reason($e),
                ];
            }
        }

        if ($gutschriftOffen !== []) {
            Log::error('invoices:pending: '.count($gutschriftOffen).' erstattete Zahlungen ohne Gutschrift.', [
                'payment_ids' => array_column($gutschriftOffen, 0),
            ]);
        }

        if ($gutschriftGeschrieben > 0) {
            $this->components->info($gutschriftGeschrieben.' Gutschriften geschrieben.');
        }

        if ($gutschriftOffen !== []) {
            $this->newLine();
            $this->components->warn('Voll erstattet, ohne Gutschrift:');
            $this->table(['Zahlung', 'Produkt', 'Land', 'Grund'], $gutschriftOffen);
            $this->newLine();
            $this->components->warn(
                count($gutschriftOffen).' Zahlungen erstattet, ohne Gutschrift. Mit --write wird die '
                .'Gutschrift nachgeschrieben, wo das geht.'
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
        return $gescheitert || ($this->option('write') && ($offen !== [] || $gutschriftOffen !== []))
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Fully refunded payments that have an invoice but no credit note.
     *
     * The rule is the writer's, not a new one: the listener writes a credit note
     * only when the refund is full (`isFull` is `refunded_cent >= amount_cent`,
     * see the payments addon's Refunds), and `creditNoteFor()` reverses only a
     * payment that has an invoice. A partial refund therefore gets no credit note
     * by design and is not in this list. The status stays `paid` after a refund,
     * so it is not checked here.
     */
    protected function erstattetOhneGutschrift(?Brand $brand): Collection
    {
        return $this->forBrand(Payment::query(), $brand)
            ->where('refunded_cent', '>', 0)
            ->whereColumn('refunded_cent', '>=', 'amount_cent')
            ->whereIn('id', Invoice::query()->where('kind', Invoice::KIND_INVOICE)->whereNotNull('payment_id')->pluck('payment_id'))
            ->whereNotIn('id', Invoice::query()->where('kind', Invoice::KIND_CREDIT_NOTE)->whereNotNull('payment_id')->pluck('payment_id'))
            ->with('items')
            ->orderBy('id')
            ->get();
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
