<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices that sit in a different brand's series than their payment's.
 *
 * The measurement after a repair. Until `brandIdFor()` read the brand off the
 * payment, an invoice written where no brand was current — a webhook, a console
 * run, a follow-up charge, which is nearly everywhere invoices are written —
 * took the default brand's number series and the default brand's sender. That
 * left no error, no log line and nothing on the row saying it had happened;
 * the only surviving trace is that the invoice says one brand and the payment
 * it belongs to says another. This command is that comparison, and it is the
 * only way to find out how many there are.
 *
 * **It reports and changes nothing, deliberately.** An invoice is immutable
 * once issued, and the number is the part that could not be corrected even if
 * the row were writable: it was handed out by one brand's gapless counter and
 * counted there. Moving it would leave a hole in one series and a stranger in
 * the other. What a wrong document needs is a credit note plus a new invoice
 * in the right series — a decision for a person, not for a batch job.
 *
 * The exit code is non-zero when there are findings, so that a scheduled run
 * says so without anybody reading its output.
 */
class BrandCheck extends Command
{
    protected $signature = 'invoices:brand-check';

    protected $description = 'Rechnungen, deren Marke nicht die ihrer Zahlung ist.';

    public function handle(): int
    {
        $rechnungen = (new Invoice)->getTable();
        $zahlungen = (new Payment)->getTable();

        if (! Schema::hasTable($zahlungen) || ! Schema::hasTable($rechnungen)) {
            $this->components->error('Ohne die Tabellen '.$zahlungen.' und '.$rechnungen.' gibt es nichts zu vergleichen. Migrationen laufen lassen.');

            return self::FAILURE;
        }

        // Die Spalte kam mit statamic-payments 1.13. Ohne sie ist die Frage
        // dieses Befehls nicht beantwortbar, und das gehoert gesagt: ein
        // Pruefbefehl, der stumm eine leere Liste zeigt, liest sich wie eine
        // Entwarnung.
        if (! Schema::hasColumn($zahlungen, 'brand_id')) {
            $this->components->warn(
                'Die Zahlungen tragen noch keine Marke (Spalte '.$zahlungen.'.brand_id fehlt, sie kommt mit '
                .'statamic-payments 1.13). Bis dahin laesst sich nicht pruefen, welche Rechnung in der '
                .'falschen Reihe steht.'
            );

            return self::FAILURE;
        }

        $ohneZahlung = Invoice::query()->whereNull('payment_id')->count();

        $abweichungen = DB::table($rechnungen)
            ->join($zahlungen, $zahlungen.'.id', '=', $rechnungen.'.payment_id')
            ->whereColumn($rechnungen.'.brand_id', '!=', $zahlungen.'.brand_id')
            ->orderBy($rechnungen.'.id')
            ->get([
                $rechnungen.'.number as number',
                $rechnungen.'.kind as kind',
                $rechnungen.'.issued_at as issued_at',
                $rechnungen.'.brand_id as rechnung_marke',
                $zahlungen.'.id as zahlung',
                $zahlungen.'.brand_id as zahlung_marke',
            ]);

        if ($ohneZahlung > 0) {
            // Storno und Rechnung haengen beide an einer Zahlung; eine ohne ist
            // von Hand entstanden. Sie ist nicht falsch, sie ist nur nicht
            // vergleichbar, und das ist etwas anderes als "geprueft".
            $this->components->info($ohneZahlung.' Rechnungen ohne Zahlung — die lassen sich nicht vergleichen.');
        }

        if ($abweichungen->isEmpty()) {
            $this->components->info('Jede Rechnung steht in der Reihe der Marke, die verkauft hat.');

            return self::SUCCESS;
        }

        $marken = $this->brandNames();

        $this->newLine();
        $this->table(
            ['Rechnung', 'Art', 'Ausgestellt', 'Zahlung', 'erwartet', 'tatsächlich'],
            $abweichungen->map(fn ($zeile) => [
                $zeile->number,
                $zeile->kind === Invoice::KIND_CREDIT_NOTE ? 'Storno' : 'Rechnung',
                (string) $zeile->issued_at,
                $zeile->zahlung,
                $this->brand((int) $zeile->zahlung_marke, $marken),
                $this->brand((int) $zeile->rechnung_marke, $marken),
            ])->all()
        );
        $this->newLine();

        $this->components->warn(
            $abweichungen->count().' Rechnungen stehen in der Reihe einer anderen Marke als der, die '
            .'verkauft hat. Nichts davon wird hier umgeschrieben: die Nummer stammt aus dem lückenlosen '
            .'Zähler dieser Marke und ist dort gezählt worden. Wo es zählt, hilft nur ein Storno und '
            .'eine neue Rechnung in der richtigen Reihe.'
        );

        return self::FAILURE;
    }

    /**
     * Marken-Ids in etwas, das ein Mensch wiedererkennt.
     *
     * Ohne brand-context oder ohne dessen Tabelle bleibt es bei der Zahl. Ein
     * Befehl, der nur wegen der Beschriftung an einer fehlenden Tabelle
     * stirbt, waere die Vermessung nicht wert.
     *
     * @return array<int, string>
     */
    protected function brandNames(): array
    {
        if (! Schema::hasTable('brands')) {
            return [];
        }

        try {
            return DB::table('brands')->pluck('handle', 'id')
                ->map(fn ($handle) => (string) $handle)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param  array<int, string>  $marken */
    protected function brand(int $id, array $marken): string
    {
        if ($id === 0) {
            // Nicht "unbekannt": die Zeile gehoert nachweislich keiner Marke,
            // und genau das ist der Fall, den der Rueckfall im InvoiceWriter
            // protokolliert.
            return '0 (keine Marke)';
        }

        return isset($marken[$id]) ? $id.' ('.$marken[$id].')' : (string) $id;
    }
}
