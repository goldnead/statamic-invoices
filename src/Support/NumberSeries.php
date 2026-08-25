<?php

namespace Goldnead\Invoices\Support;

use Goldnead\Invoices\Exceptions\SeriesWouldCollide;
use Goldnead\Invoices\Models\InvoiceCounter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The next invoice number, and why it is not `MAX() + 1`.
 *
 * German law wants a series that is unique and continuous. Both are properties
 * of *concurrency*, not of arithmetic: two checkouts finishing in the same
 * millisecond both read the same maximum and both write the same number, and
 * neither of them notices. A unique index turns that into an exception — better
 * than a duplicate, but it happens after somebody has already paid, and the
 * retry has to be built anyway.
 *
 * So the number comes from a counter row that is locked while it is
 * incremented, inside the transaction that writes the invoice. Two processes
 * queue; nobody gets the same number; nothing is skipped.
 *
 * **A number is only ever taken inside the transaction that writes the
 * invoice.** Taking one and then failing would leave a gap, and a gap in a
 * German invoice series is a question at the next audit, not a cosmetic issue.
 */
class NumberSeries
{
    /**
     * Take the next number in a series and hand it out exactly once.
     *
     * Must run inside a transaction. It does not open one itself, deliberately:
     * the lock has to be held until the invoice row exists, and a method that
     * committed on its own would release it a moment too early.
     */
    public function take(?int $brandId, ?Carbon $at = null): string
    {
        if (! DB::transactionLevel()) {
            throw new \LogicException(
                'A number must be taken inside the transaction that writes the invoice, '
                .'otherwise a failure leaves a gap in the series.'
            );
        }

        $at ??= Carbon::now();

        // `0` statt NULL fuer "keine Marke". Ein Unique-Index bindet bei NULL
        // nicht: zwei Zaehlerzeilen fuer dieselbe Reihe gingen sonst durch, und
        // zwar auf jeder Installation ohne brand-context — also der Mehrheit.
        $brandId = (int) ($brandId ?? 0);
        $series = $this->series($brandId, $at);

        // `lockForUpdate` on a row that may not exist yet: create it first, then
        // lock. `firstOrCreate` is safe here because the unique index decides,
        // and the loser reads the winner's row.
        try {
            InvoiceCounter::query()->firstOrCreate(
                ['brand_id' => $brandId, 'series' => $series],
                ['last_number' => 0],
            );
        } catch (UniqueConstraintViolationException) {
            // Somebody else created it between the read and the write. Their
            // row is the truth.
        }

        $counter = InvoiceCounter::query()
            ->where('brand_id', $brandId)
            ->where('series', $series)
            ->lockForUpdate()
            ->firstOrFail();

        $counter->last_number++;
        $counter->save();

        return $this->format($series, $counter->last_number);
    }

    /**
     * The series a date belongs to, as a literal string.
     *
     * Stored rather than computed on read: a site that changes its format next
     * year must not retroactively change which series an old invoice was in.
     */
    public function series(?int $brandId, Carbon $at): string
    {
        $prefix = $this->prefixFor($brandId);
        $period = $at->format((string) config('invoices.number.period', 'Y-m'));

        return $period === '' ? $prefix : $prefix.$period;
    }

    public function format(string $series, int $number): string
    {
        $pad = max(1, (int) config('invoices.number.pad', 3));

        return $series.(string) config('invoices.number.separator', '-')
            .str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * The prefix of a brand's own series.
     *
     * One series per brand, because two brands sharing a counter produce a
     * series with holes in it from each brand's point of view — and it is each
     * brand that has to answer for its own numbering.
     */
    protected function prefixFor(?int $brandId): string
    {
        $default = (string) config('invoices.number.prefix', 'RE');

        if (! $brandId) {
            return $default;
        }

        $perBrand = (array) config('invoices.number.prefix_per_brand', []);

        if (isset($perBrand[$brandId])) {
            return (string) $perBrand[$brandId];
        }

        // Keine eigene Vorsilbe, und die Installation faehrt mehrere Marken.
        //
        // Der Zaehler ist je Marke — das ist die Zusage. Die Nummer ist global
        // eindeutig — das macht sie zum Beleg. Beides zusammen mit derselben
        // Vorsilbe heisst: zwei Marken geben RE2026-08-001 aus, die erste
        // gewinnt, und die zweite ist eine Ausnahme auf einer bezahlten
        // Bestellung. Eine Vorsilbe aus dem Handle abzuleiten waere geraten und
        // wuerde die Nummerierung einer Installation still aendern, sobald sie
        // eine Marke dazunimmt.
        if ($this->multiBrand()) {
            throw new SeriesWouldCollide($brandId);
        }

        return $default;
    }

    /** Whether this installation runs more than one brand at all. */
    protected function multiBrand(): bool
    {
        if (! class_exists('\Goldnead\BrandContext\Facades\BrandContext')) {
            return false;
        }

        try {
            return (bool) app('brand-context')->multiBrandEnabled();
        } catch (\Throwable) {
            return false;
        }
    }
}
