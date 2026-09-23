<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

use Goldnead\Invoices\Support\TaxResult;

/**
 * What a period adds up to, per tax treatment, country and rate.
 *
 * The figures a return is filled in from, not the return itself. Which line of
 * which form a figure belongs on is the adviser's call, and this report does
 * not pretend otherwise; what it guarantees is that the figures are the sum of
 * the documents, credit notes subtracted, nothing recalculated.
 *
 * **§ 19 comes out as turnover without tax.** A small business still reports
 * its turnover (the 25.000 € / 100.000 € limits of § 19 Abs. 1 UStG are
 * measured on it), so those lines are listed with their amount and a tax of
 * zero, under their own treatment, rather than dropped.
 *
 * **OSS separately.** {@see self::ossTax()} is the tax owed in other member
 * states on ordinary taxed lines, which is what a One-Stop-Shop return
 * declares. Everything at home stays in the domestic figures.
 *
 * Built in one pass over the documents, lazily; only the sums are kept.
 */
final class TaxReport
{
    /** The order a person reads the treatments in: taxed first, then the reasons for none. */
    public const ORDER = [
        TaxResult::MECHANISM_STANDARD,
        TaxResult::MECHANISM_SMALL_BUSINESS,
        TaxResult::MECHANISM_EXEMPT,
        TaxResult::MECHANISM_REVERSE_CHARGE,
        TaxResult::MECHANISM_INTRA_COMMUNITY_SUPPLY,
        TaxResult::MECHANISM_OUTSIDE_SCOPE,
        TaxResult::MECHANISM_EXPORT,
    ];

    /**
     * @param  list<array{mechanism: string, country: string|null, rate_bp: int, net: int, tax: int, gross: int, documents: int}>  $rows
     * @param  array{net: int, tax: int, gross: int}  $totals
     * @param  array<string, true>  $currencies
     */
    private function __construct(
        public readonly Period $period,
        public readonly ?int $brandId,
        public readonly array $rows,
        public readonly array $totals,
        public readonly int $documents,
        public readonly int $creditNotes,
        public readonly int $derived,
        public readonly string $merchantCountry,
        public readonly array $currencies,
    ) {}

    public static function for(Period $period, ?int $brandId = null): self
    {
        $rows = [];
        $documents = 0;
        $creditNotes = 0;
        $derived = 0;
        $currencies = [];

        foreach (Documents::lazy($period, $brandId) as $invoice) {
            $documents++;
            $sign = Documents::sign($invoice);
            $currencies[(string) $invoice->currency] = true;

            if ($sign < 0) {
                $creditNotes++;
            }

            $seen = [];

            foreach ($invoice->items as $line) {
                $treatment = Documents::treatment($invoice, $line);
                $key = $treatment['mechanism'].'|'.$treatment['country'].'|'.$line->tax_rate_bp;

                if ($treatment['derived']) {
                    $derived++;
                }

                $rows[$key] ??= [
                    'mechanism' => $treatment['mechanism'],
                    'country' => $treatment['country'],
                    'rate_bp' => (int) $line->tax_rate_bp,
                    'net' => 0,
                    'tax' => 0,
                    'gross' => 0,
                    'documents' => 0,
                ];

                $rows[$key]['net'] += $sign * (int) $line->net_cent;
                $rows[$key]['tax'] += $sign * (int) $line->tax_cent;
                $rows[$key]['gross'] += $sign * (int) $line->gross_cent;

                if (! isset($seen[$key])) {
                    $rows[$key]['documents']++;
                    $seen[$key] = true;
                }
            }
        }

        $order = array_flip(self::ORDER);

        usort($rows, fn (array $a, array $b) => [$order[$a['mechanism']] ?? 99, (string) $a['country'], -$a['rate_bp']]
            <=> [$order[$b['mechanism']] ?? 99, (string) $b['country'], -$b['rate_bp']]);

        return new self(
            period: $period,
            brandId: $brandId,
            rows: $rows,
            totals: [
                'net' => array_sum(array_column($rows, 'net')),
                'tax' => array_sum(array_column($rows, 'tax')),
                'gross' => array_sum(array_column($rows, 'gross')),
            ],
            documents: $documents,
            creditNotes: $creditNotes,
            derived: $derived,
            merchantCountry: strtoupper((string) config('invoices.tax.merchant_country', 'DE')),
            currencies: $currencies,
        );
    }

    /** Tax on ordinary lines whose place of supply is another member state: the OSS figure. */
    public function ossTax(): int
    {
        return array_sum(array_column($this->ossRows(), 'tax'));
    }

    /** @return list<array{mechanism: string, country: string|null, rate_bp: int, net: int, tax: int, gross: int, documents: int}> */
    public function ossRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            fn (array $row) => $row['mechanism'] === TaxResult::MECHANISM_STANDARD
                && $row['country'] !== null
                && $row['country'] !== $this->merchantCountry,
        ));
    }

    /** More than one currency in the period: the totals add apples to pears, and the screen says so. */
    public function mixesCurrencies(): bool
    {
        return count($this->currencies) > 1;
    }

    /**
     * The report as rows for a CSV, with the same format rules as the document export.
     *
     * @param  resource  $stream
     */
    public function writeCsv($stream, CsvFormat $format): void
    {
        fwrite($stream, $format->preamble());
        $format->put($stream, ['Steuerart', 'Leistungsort', 'Steuersatz', 'Netto', 'Steuer', 'Brutto', 'Belege']);

        foreach ($this->rows as $row) {
            $format->put($stream, [
                CsvExport::TREATMENTS[$row['mechanism']] ?? $row['mechanism'],
                $row['country'],
                $format->rate($row['rate_bp']),
                $format->amount($row['net']),
                $format->amount($row['tax']),
                $format->amount($row['gross']),
                $row['documents'],
            ]);
        }

        $format->put($stream, [
            'Summe', null, null,
            $format->amount($this->totals['net']),
            $format->amount($this->totals['tax']),
            $format->amount($this->totals['gross']),
            $this->documents,
        ]);
    }
}
