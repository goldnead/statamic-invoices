<?php

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\Invoices\Export\CsvExport;
use Goldnead\Invoices\Export\CsvFormat;
use Goldnead\Invoices\Export\PdfArchive;
use Goldnead\Invoices\Export\Period;
use Goldnead\Invoices\Export\TaxReport;
use Goldnead\Invoices\Jobs\BuildPdfArchive;
use Illuminate\Console\Command;
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
    protected $signature = 'invoices:export
        {what : csv, report or pdf}
        {--from= : Erster Tag, YYYY-MM-DD}
        {--to= : Letzter Tag, YYYY-MM-DD}
        {--month= : Ein Monat, YYYY-MM}
        {--quarter= : Ein Quartal, YYYY-Q1 bis YYYY-Q4}
        {--year= : Ein Jahr, YYYY}
        {--brand= : Nur die Belege dieser Marke (ID)}
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

        $brand = $this->option('brand');
        $brandId = is_numeric($brand) ? (int) $brand : null;

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

    private function csv(Period $period, CsvFormat $format, ?int $brandId): int
    {
        $output = $this->option('output');
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
        $output = $this->option('output');

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

        $output = $this->option('output');

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
