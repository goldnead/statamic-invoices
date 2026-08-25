<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\Invoices\Exceptions\SeriesWouldCollide;
use Goldnead\Invoices\Support\NumberSeries;
use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * Two brands must not count in the same series.
 *
 * The counter is per brand — that is the promise. The number is globally
 * unique — that is what makes it evidence. Put those together with a shared
 * prefix and both brands hand out `RE2026-08-001`: the first wins, and the
 * second is an exception on an order somebody already paid for.
 *
 * Found in the demo, not in a test: two brands wrote invoices, both prefixed
 * `RE`, and the second one died on the unique index.
 */
class OneSeriesPerBrandTest extends TestCase
{
    /**
     * brand-context nur hier, nicht in der Basis.
     *
     * Die Frage dieser Datei ist genau die, die es ohne Mehrmarkenbetrieb nicht
     * gibt — und die uebrige Suite soll weiter ohne den Nachbarn laufen, damit
     * sie auch dort gruen ist, wo er nicht installiert ist.
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class) ? ServiceProvider::class : null,
            class_exists(\Goldnead\BrandContext\ServiceProvider::class) ? \Goldnead\BrandContext\ServiceProvider::class : null,
        ])));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\Goldnead\BrandContext\ServiceProvider::class)) {
            $this->markTestSkipped('brand-context is what this file is about.');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->artisan('migrate')->run();
    }

    private function nimm(?int $brandId, ?Carbon $at = null): string
    {
        return DB::transaction(fn () => app(NumberSeries::class)->take($brandId, $at));
    }

    #[Test]
    public function a_brand_without_its_own_prefix_is_refused_rather_than_left_to_collide(): void
    {
        config(['brand-context.multi_brand' => true]);
        app('brand-context')->forget();

        $this->expectException(SeriesWouldCollide::class);

        $this->nimm(7);
    }

    #[Test]
    public function each_brand_with_its_own_prefix_counts_separately(): void
    {
        config([
            'brand-context.multi_brand' => true,
            'invoices.number.prefix_per_brand' => [3 => 'CW', 4 => 'HM'],
        ]);
        app('brand-context')->forget();

        $at = Carbon::parse('2026-08-15 10:00');

        $this->assertSame('CW2026-08-001', $this->nimm(3, $at));
        $this->assertSame('HM2026-08-001', $this->nimm(4, $at));
        $this->assertSame('CW2026-08-002', $this->nimm(3, $at));
    }

    #[Test]
    public function a_single_brand_installation_needs_no_prefix_at_all(): void
    {
        // Ohne Mehrmarkenbetrieb gibt es nichts zu kollidieren, und eine
        // Pflichtangabe waere hier nur eine Huerde ohne Zweck.
        config(['brand-context.multi_brand' => false]);
        app('brand-context')->forget();

        $this->assertSame('RE2026-08-001', $this->nimm(0, Carbon::parse('2026-08-15')));
    }
}
