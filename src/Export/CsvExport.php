<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\TaxResult;

/**
 * Every invoice and credit note of a period, one row per document and rate.
 *
 * Per rate, not per document, because that is the unit a booking has: one
 * revenue account per rate. A document with a course at 19 % and sheet music
 * at 7 % is two bookings, and a single row with a mixed total cannot be posted.
 *
 * **Credit notes carry a minus.** The table stores them unsigned and the PDF
 * says "Stornorechnung"; a column of amounts has no such sentence, so the sign
 * is the only way a sum over the file comes out right.
 *
 * **The column names are German and fixed**, whatever language the Control
 * Panel speaks. An import mapping in DATEV or Lexware is saved against the
 * header row, and a header that changes with the user's locale breaks it the
 * next month. The treatment column (`Steuerart`) is German for the same reason.
 *
 * Written to a stream row by row. Nothing about the file is held in memory
 * beyond the two hundred documents currently being read.
 */
final class CsvExport
{
    public const COLUMNS = [
        'Belegart',
        'Belegnummer',
        'Belegdatum',
        'Bezug',
        'Kunde',
        'E-Mail',
        'Land',
        'USt-IdNr.',
        'Leistungsort',
        'Steuerart',
        'Steuersatz',
        'Netto',
        'Steuer',
        'Brutto',
        'Währung',
        'Buchungstext',
        'Marke',
    ];

    public const TREATMENTS = [
        TaxResult::MECHANISM_STANDARD => 'steuerpflichtig',
        TaxResult::MECHANISM_SMALL_BUSINESS => 'Kleinunternehmer',
        TaxResult::MECHANISM_REVERSE_CHARGE => 'Reverse Charge',
        TaxResult::MECHANISM_INTRA_COMMUNITY_SUPPLY => 'innergemeinschaftliche Lieferung',
        TaxResult::MECHANISM_EXPORT => 'Ausfuhr',
        TaxResult::MECHANISM_OUTSIDE_SCOPE => 'nicht steuerbar',
        TaxResult::MECHANISM_EXEMPT => 'steuerfrei',
    ];

    public function __construct(private readonly CsvFormat $format) {}

    public function format(): CsvFormat
    {
        return $this->format;
    }

    /**
     * @param  resource  $stream
     * @return int the number of rows written, header not counted
     */
    public function write($stream, Period $period, ?int $brandId = null): int
    {
        fwrite($stream, $this->format->preamble());
        $this->format->put($stream, self::COLUMNS);

        $rows = 0;

        foreach (Documents::lazy($period, $brandId) as $invoice) {
            foreach ($this->rowsFor($invoice) as $row) {
                $this->format->put($stream, $row);
                $rows++;
            }
        }

        return $rows;
    }

    /**
     * @return list<list<string|int|null>>
     */
    public function rowsFor(Invoice $invoice): array
    {
        $sign = Documents::sign($invoice);
        $groups = [];

        foreach ($invoice->items as $line) {
            $treatment = Documents::treatment($invoice, $line);
            $key = $line->tax_rate_bp.'|'.$treatment['mechanism'].'|'.$treatment['country'];

            $groups[$key] ??= [
                'rate' => (int) $line->tax_rate_bp,
                'treatment' => $treatment,
                'net' => 0,
                'tax' => 0,
                'gross' => 0,
                'names' => [],
            ];

            $groups[$key]['net'] += (int) $line->net_cent;
            $groups[$key]['tax'] += (int) $line->tax_cent;
            $groups[$key]['gross'] += (int) $line->gross_cent;
            $groups[$key]['names'][] = (string) $line->name;
        }

        $reference = $sign < 0 ? ($invoice->meta['reverses_number'] ?? $invoice->reverses?->number) : null;

        // Every column a buyer could have typed into goes through text(), which
        // keeps a spreadsheet from running it. Amounts, rates and dates are
        // ours and stay as they are, minus sign included.
        $text = fn (?string $value) => $this->format->text($value);

        return array_values(array_map(fn (array $group) => [
            $sign < 0 ? 'Storno' : 'Rechnung',
            $text($invoice->number),
            $invoice->issued_at->format('d.m.Y'),
            $text($reference),
            $text($invoice->buyer_name),
            $text($invoice->buyer_email),
            $text($invoice->buyer_country),
            $text($invoice->buyer_vat_id),
            $group['treatment']['country'],
            self::TREATMENTS[$group['treatment']['mechanism']] ?? $group['treatment']['mechanism'],
            $this->format->rate($group['rate']),
            $this->format->amount($sign * $group['net']),
            $this->format->amount($sign * $group['tax']),
            $this->format->amount($sign * $group['gross']),
            $invoice->currency,
            // DATEV cuts the booking text at 60 characters. Cut here, with a
            // mark that says so, rather than there, silently.
            $text(mb_strimwidth(($sign < 0 ? 'Storno ' : '').implode(', ', array_unique($group['names'])), 0, 60, '…')),
            $text($this->brandName((int) $invoice->brand_id)),
        ], $groups));
    }

    /** @var array<int, string|null> */
    private array $brandNames = [];

    /**
     * The brand's name, as the bookkeeper knows it, or its id when brand-context
     * is not there to ask. Empty for an installation without brands.
     */
    private function brandName(int $brandId): ?string
    {
        if ($brandId === 0) {
            return null;
        }

        if (! array_key_exists($brandId, $this->brandNames)) {
            $name = null;

            if (class_exists('\Goldnead\BrandContext\Models\Brand')) {
                try {
                    $name = Brand::query()->whereKey($brandId)->value('name');
                } catch (\Throwable) {
                    $name = null;
                }
            }

            $this->brandNames[$brandId] = is_string($name) && $name !== '' ? $name : (string) $brandId;
        }

        return $this->brandNames[$brandId];
    }
}
