<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\NumberSeries;
use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The number series, which is the part of an invoice that has to be right.
 *
 * German law wants it unique and continuous. Both are properties of
 * concurrency, not of arithmetic — which is why the prior art this addon
 * replaces (`MAX() + 1` over a `LIKE` query) is wrong in a way that only shows
 * under load, and then shows as two customers holding the same invoice number.
 */
class NumberSeriesTest extends TestCase
{
    private function nimm(?int $brandId = null, ?Carbon $at = null): string
    {
        return DB::transaction(fn () => app(NumberSeries::class)->take($brandId, $at));
    }

    #[Test]
    public function numbers_run_without_gaps(): void
    {
        $at = Carbon::parse('2026-08-15 10:00');

        $nummern = array_map(fn () => $this->nimm(null, $at), range(1, 5));

        $this->assertSame(
            ['RE2026-08-001', 'RE2026-08-002', 'RE2026-08-003', 'RE2026-08-004', 'RE2026-08-005'],
            $nummern,
        );
    }

    #[Test]
    public function a_failed_write_leaves_no_gap_in_the_series(): void
    {
        // Die eigentliche Zusage. Eine Nummer, die vergeben wird und deren
        // Rechnung dann scheitert, ist eine Lücke — und eine Lücke ist eine
        // Frage bei der nächsten Prüfung, kein Schönheitsfehler. Deshalb liegt
        // die Vergabe in derselben Transaktion wie die Zeile.
        $at = Carbon::parse('2026-08-15 10:00');

        $this->assertSame('RE2026-08-001', $this->nimm(null, $at));

        try {
            DB::transaction(function () use ($at) {
                app(NumberSeries::class)->take(null, $at);

                throw new \RuntimeException('irgendetwas geht schief');
            });
        } catch (\RuntimeException) {
            // Erwartet.
        }

        // Die 002 ist wieder frei: der Zähler ist mit zurückgerollt.
        $this->assertSame('RE2026-08-002', $this->nimm(null, $at));
    }

    #[Test]
    public function each_brand_counts_in_its_own_series(): void
    {
        // Zwei Marken, die sich einen Zähler teilen, geben jeder eine Reihe mit
        // Löchern — und jede Marke muss für ihre eigene Nummerierung geradestehen.
        config(['invoices.number.prefix_per_brand' => [3 => 'CW', 4 => 'HM']]);

        $at = Carbon::parse('2026-08-15 10:00');

        $this->assertSame('CW2026-08-001', $this->nimm(3, $at));
        $this->assertSame('HM2026-08-001', $this->nimm(4, $at));
        $this->assertSame('CW2026-08-002', $this->nimm(3, $at));
    }

    #[Test]
    public function a_new_period_starts_a_new_series(): void
    {
        $this->assertSame('RE2026-08-001', $this->nimm(null, Carbon::parse('2026-08-31 23:59')));
        $this->assertSame('RE2026-09-001', $this->nimm(null, Carbon::parse('2026-09-01 00:00')));
        $this->assertSame('RE2026-08-002', $this->nimm(null, Carbon::parse('2026-08-31 23:59')));
    }

    #[Test]
    public function a_yearly_series_is_a_setting_and_not_a_rebuild(): void
    {
        config(['invoices.number.period' => 'Y', 'invoices.number.pad' => 4]);

        $this->assertSame('RE2026-0001', $this->nimm(null, Carbon::parse('2026-03-01')));
        $this->assertSame('RE2026-0002', $this->nimm(null, Carbon::parse('2026-11-01')));
    }

    #[Test]
    public function the_same_number_can_never_exist_twice(): void
    {
        // Der Riegel liegt in der Datenbank und nicht im Code: ein Unique-Index
        // ist das Einzige, was auch dann hält, wenn zwei Prozesse gleichzeitig
        // schreiben.
        Invoice::create([
            'number' => 'RE2026-08-001', 'issued_at' => now(), 'currency' => 'EUR',
            'net_cent' => 100, 'tax_cent' => 19, 'gross_cent' => 119,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Invoice::create([
            'number' => 'RE2026-08-001', 'issued_at' => now(), 'currency' => 'EUR',
            'net_cent' => 100, 'tax_cent' => 19, 'gross_cent' => 119,
        ]);
    }
}
