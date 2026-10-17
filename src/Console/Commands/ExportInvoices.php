<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Invoices\Console\Concerns\RunsPerBrand;
use Goldnead\Invoices\Export\ArchiveStore;
use Goldnead\Invoices\Export\CsvExport;
use Goldnead\Invoices\Export\CsvFormat;
use Goldnead\Invoices\Export\Documents;
use Goldnead\Invoices\Export\PdfArchive;
use Goldnead\Invoices\Export\Period;
use Goldnead\Invoices\Export\TaxReport;
use Goldnead\Invoices\Jobs\BuildPdfArchive;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/**
 * The three exports from the command line, for a cron job or an adviser's
 * monthly routine.
 *
 *     php artisan invoices:export csv --month=2026-08 --output=august.csv
 *     php artisan invoices:export report --quarter=2026-Q3
 *     php artisan invoices:export pdf --year=2025 --output=belege-2025.zip
 *     php artisan invoices:export pdf --year=2025 --queue
 *
 * Without a period option it takes the previous calendar month and says so.
 * A CSV without `--output` goes to standard output, so it can be piped.
 */
class ExportInvoices extends Command
{
    use RunsPerBrand;

    protected $signature = 'invoices:export
        {what : csv, report or pdf}
        {--from= : Erster Tag, YYYY-MM-DD}
        {--to= : Letzter Tag, YYYY-MM-DD}
        {--month= : Ein Monat, YYYY-MM}
        {--quarter= : Ein Quartal, YYYY-Q1 bis YYYY-Q4}
        {--year= : Ein Jahr, YYYY}
        {--brand= : Nur die Belege dieser Marke (Handle oder ID); ohne sie läuft jede Marke für sich}
        {--delimiter= : ; , oder tab}
        {--encoding= : utf-8-bom, utf-8 oder windows-1252}
        {--decimal= : , oder .}
        {--output= : Zieldatei; ohne sie geht CSV auf die Standardausgabe}
        {--queue : Das PDF-Archiv als Job einreihen statt hier zu bauen}';

    protected $description = 'Export invoices and credit notes of a period: CSV, tax report or a ZIP of the PDFs.';

    public function handle(): int
    {
        try {
            $period = Period::fromOptions($this->options());

            if ($period === null) {
                $period = Period::lastMonth();
                $this->components->info(sprintf('Kein Zeitraum angegeben, genommen: %s.', $period->label()));
            }

            $format = CsvFormat::fromOptions($this->options());
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->refusesStandardOutput()) {
            $this->components->error(
                'Auf einem Mehrmarken-Host gehört ein CSV auf Standardausgabe zu genau einer Marke: '
                .'--brand=<Handle oder ID> angeben, oder --output=datei.csv (dann entsteht eine Datei je Marke).'
            );

            return self::FAILURE;
        }

        try {
            $exit = $this->forEveryBrand(fn (?Brand $brand) => $this->export($period, $format, $brand));
        } catch (ModelNotFoundException) {
            $this->components->error(sprintf('Die Marke "%s" gibt es nicht.', (string) $this->option('brand')));

            return self::FAILURE;
        }

        return $this->unbrandedDocuments($period) ? self::FAILURE : $exit;
    }

    /**
     * One export, for one brand on a multi-brand host and for everything on a
     * single-brand one. Run inside the brand, so the tax report reads that
     * brand's merchant country and the texts it saved, not the package's.
     */
    private function export(Period $period, CsvFormat $format, ?Brand $brand): int
    {
        $option = $this->option('brand');
        $brandId = $brand->id ?? (is_numeric($option) ? (int) $option : null);

        try {
            return match ($this->argument('what')) {
                'csv' => $this->csv($period, $format, $brandId),
                'report' => $this->report($period, $format, $brandId),
                'pdf' => $this->pdf($period, $brandId),
                default => $this->unknown(),
            };
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /** Several brands at once: each gets its own file, named like the archives (`_marke-2`). */
    private function suffixesOutput(): bool
    {
        return $this->multiBrandActive() && ! $this->option('brand');
    }

    /** A CSV on standard output cannot be several brands' CSVs one after the other. */
    private function refusesStandardOutput(): bool
    {
        return $this->argument('what') === 'csv'
            && ! $this->hasOutput()
            && $this->suffixesOutput()
            && Brand::query()->count() > 1;
    }

    private function hasOutput(): bool
    {
        $output = $this->option('output');

        return is_string($output) && $output !== '';
    }

    /** The target file for this brand: `belege.csv` becomes `belege_marke-2.csv` when several brands are written. */
    private function outputFor(?int $brandId): ?string
    {
        if (! $this->hasOutput()) {
            return null;
        }

        $output = (string) $this->option('output');

        if (! $this->suffixesOutput() || $brandId === null) {
            return $output;
        }

        $parts = pathinfo($output);
        $stem = str_contains($output, '/') ? $parts['dirname'].'/'.$parts['filename'] : $parts['filename'];

        return $stem.ArchiveStore::brandSuffix($brandId).(isset($parts['extension']) ? '.'.$parts['extension'] : '');
    }

    /** CSV on standard output has no "Brand: x" line in front of the data. */
    protected function shouldAnnounceBrand(): bool
    {
        return ! ($this->argument('what') === 'csv' && ! $this->hasOutput());
    }

    /**
     * Documents that no brand run reached.
     *
     * An invoice whose `brand_id` names no brand (or `0` on a host that has
     * since turned multi-brand on) is in no per-brand export. A tax export that
     * leaves a document out without saying so is worse than none, so it says so
     * and fails.
     */
    private function unbrandedDocuments(Period $period): bool
    {
        if (! $this->multiBrandActive() || $this->option('brand')) {
            return false;
        }

        $count = Documents::query($period)->whereNotIn('brand_id', Brand::query()->pluck('id'))->count();

        if ($count === 0) {
            return false;
        }

        $this->components->warn(sprintf(
            '%d Belege dieses Zeitraums gehören zu keiner Marke und stehen in keinem der Exporte. '
            .'Mit invoices:brand-check suchen, dann von Hand zuordnen.',
            $count,
        ));

        return true;
    }

    private function csv(Period $period, CsvFormat $format, ?int $brandId): int
    {
        $output = $this->outputFor($brandId);
        $stream = is_string($output) && $output !== '' ? fopen($output, 'wb') : fopen('php://stdout', 'wb');

        if ($stream === false) {
            $this->components->error(sprintf('%s lässt sich nicht schreiben.', (string) $output));

            return self::FAILURE;
        }

        $rows = (new CsvExport($format))->write($stream, $period, $brandId);
        fclose($stream);

        if (is_string($output) && $output !== '') {
            $this->components->info(sprintf('%d Zeilen für %s nach %s geschrieben.', $rows, $period->label(), $output));
        }

        return self::SUCCESS;
    }

    private function report(Period $period, CsvFormat $format, ?int $brandId): int
    {
        $report = TaxReport::for($period, $brandId);
        $output = $this->outputFor($brandId);

        if (is_string($output) && $output !== '') {
            $stream = fopen($output, 'wb');

            if ($stream === false) {
                $this->components->error(sprintf('%s lässt sich nicht schreiben.', $output));

                return self::FAILURE;
            }

            $report->writeCsv($stream, $format);
            fclose($stream);
        }

        $this->components->info(sprintf('Steuerbericht %s: %d Belege, davon %d Stornos.', $period->label(), $report->documents, $report->creditNotes));

        $euro = fn (int $cent) => number_format($cent / 100, 2, ',', '.');

        $this->table(
            ['Steuerart', 'Leistungsort', 'Satz', 'Netto', 'Steuer', 'Brutto', 'Belege'],
            [
                ...array_map(fn (array $row) => [
                    CsvExport::TREATMENTS[$row['mechanism']] ?? $row['mechanism'],
                    $row['country'] ?? '',
                    rtrim(rtrim(number_format($row['rate_bp'] / 100, 2, ',', ''), '0'), ',').' %',
                    $euro($row['net']),
                    $euro($row['tax']),
                    $euro($row['gross']),
                    $row['documents'],
                ], $report->rows),
                ['Summe', '', '', $euro($report->totals['net']), $euro($report->totals['tax']), $euro($report->totals['gross']), $report->documents],
            ],
        );

        if ($report->ossRows() !== []) {
            $this->components->info(sprintf('Davon im Ausland geschuldet (OSS): %s Steuer.', $euro($report->ossTax())));
        }

        if ($report->derived > 0) {
            $this->components->warn(sprintf(
                '%d Zeilen stammen aus der Zeit vor 2.2 und tragen Steuerart und Leistungsort nicht selbst; beides ist abgeleitet.',
                $report->derived,
            ));
        }

        if ($report->mixesCurrencies()) {
            $this->components->warn('Der Zeitraum enthält mehrere Währungen. Die Summen addieren sie ungeprüft.');
        }

        return self::SUCCESS;
    }

    private function pdf(Period $period, ?int $brandId): int
    {
        if ($this->option('queue')) {
            BuildPdfArchive::dispatch($period->from->toDateString(), $period->to->toDateString(), $brandId);
            $this->components->info(sprintf('Archiv für %s eingereiht.', $period->label()));

            return self::SUCCESS;
        }

        $output = $this->outputFor($brandId);

        if (! is_string($output) || $output === '') {
            $this->components->error('Für ein PDF-Archiv braucht es --output=datei.zip oder --queue.');

            return self::FAILURE;
        }

        $count = app(PdfArchive::class)->build($period, $brandId, $output);
        $this->components->info(sprintf('%d Belege für %s nach %s geschrieben.', $count, $period->label(), $output));

        return self::SUCCESS;
    }

    private function unknown(): int
    {
        $this->components->error(sprintf('"%s" gibt es nicht. Möglich sind csv, report und pdf.', (string) $this->argument('what')));

        return self::FAILURE;
    }
}
