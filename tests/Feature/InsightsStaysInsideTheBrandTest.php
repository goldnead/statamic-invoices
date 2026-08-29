<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\IdentityContracts\ServiceProvider;
use Goldnead\Invoices\Integrations\Insights\Gross;
use Goldnead\Invoices\Integrations\Insights\Issued;
use Goldnead\Invoices\Integrations\Insights\Net;
use Goldnead\Invoices\Integrations\Insights\Tax;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * A figure of this addon shows one brand's money, never four brands' money.
 *
 * Found on the running demo, not here: with "Nordlicht Studio" chosen, the
 * Invoices group reported four issued documents and 257,15 € of net — every one
 * of those documents belonging to another brand, and Nordlicht itself having
 * issued nothing at all in the window. The CRM group beside it correctly showed
 * zero. A dashboard that names a brand and prints somebody else's revenue is not
 * a rounding error, and it was invisible on a single-brand install, which is
 * where every test ran.
 *
 * So the fixture below is deliberately built the way the demo was: one document
 * for the brand being looked at and several for the others, with different
 * amounts, different countries, different tax rates and a credit note among
 * them. Every expectation is a figure that only comes out right if the
 * condition reached that particular query — and the totals across all brands are
 * written into the messages, because those are the numbers that appear when it
 * does not.
 *
 * **Three surfaces, checked separately.** Value, series and breakdown are three
 * queries, and in the hand-written brand filters this replaced they had drifted
 * apart before: a figure narrowed and its chart not, or both narrowed and the
 * split beneath them reading across the whole install. Two of the breakdowns
 * here matter for a second reason — the tax rate split runs over `invoice_items`
 * joined onto the documents, a query the declaration cannot reach and which is
 * filtered by hand.
 */
class InsightsStaysInsideTheBrandTest extends TestCase
{
    /** The day everything below is measured from. */
    protected const HEUTE = '2026-08-20 12:00:00';

    protected int $nordlicht;

    protected int $aurora;

    protected int $borealis;

    protected int $helvetia;

    /**
     * brand-context only here, not in the base.
     *
     * The question of this file is the one that does not exist without
     * multi-brand operation, and the rest of the suite should keep running where
     * the neighbour is not installed — which is also the state in which
     * `brandScoped()` has to be a no-op.
     */
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(ServiceProvider::class)
                ? ServiceProvider::class
                : null,
            class_exists(\Goldnead\BrandContext\ServiceProvider::class)
                ? \Goldnead\BrandContext\ServiceProvider::class
                : null,
        ])));
    }

    protected function setUp(): void
    {
        // Before the application exists: the contracts have to be there before a
        // metric class is loaded, and the base class before one is *declared*.
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        if (! class_exists(TableMetric::class)) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        parent::setUp();

        if (! class_exists(\Goldnead\BrandContext\ServiceProvider::class)) {
            $this->markTestSkipped('brand-context is what this file is about.');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->artisan('migrate')->run();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));

        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();

        $this->nordlicht = Brand::create(['handle' => 'nordlicht', 'name' => 'Nordlicht Studio'])->id;
        $this->aurora = Brand::create(['handle' => 'aurora', 'name' => 'Aurora'])->id;
        $this->borealis = Brand::create(['handle' => 'borealis', 'name' => 'Borealis'])->id;
        $this->helvetia = Brand::create(['handle' => 'helvetia', 'name' => 'Helvetia'])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -- The fixture --------------------------------------------------------

    /**
     * One document for Nordlicht, four for everybody else.
     *
     * Across all brands and in euros: five documents, net
     * 1000 + 2000 + 3000 − 500 = 5500, tax 190 + 380 + 210 − 95 = 685, gross
     * 1190 + 2380 + 3210 − 595 = 6185. Nordlicht's own share of that is a single
     * invoice: 1000 net, 190 tax, 1190 gross. Every assertion below is written
     * so that the unscoped answer is one of the first three numbers.
     */
    protected function fixture(): void
    {
        $this->zeile($this->rechnung([
            'brand_id' => $this->nordlicht,
            'number' => 'NL2026-08-001',
            'issued_at' => '2026-08-12 10:00:00',
            'buyer_country' => 'DE',
            'net_cent' => 1000,
            'tax_cent' => 190,
            'gross_cent' => 1190,
        ]), 1000, 1900, 190);

        $this->zeile($this->rechnung([
            'brand_id' => $this->aurora,
            'number' => 'AU2026-08-001',
            'issued_at' => '2026-08-13 10:00:00',
            'buyer_country' => 'AT',
            'net_cent' => 2000,
            'tax_cent' => 380,
            'gross_cent' => 2380,
        ]), 2000, 1900, 380);

        // Another rate, so the split by rate has two rows to get wrong.
        $this->zeile($this->rechnung([
            'brand_id' => $this->aurora,
            'number' => 'AU2026-08-002',
            'issued_at' => '2026-08-14 10:00:00',
            'buyer_country' => 'CH',
            'net_cent' => 3000,
            'tax_cent' => 210,
            'gross_cent' => 3210,
        ]), 3000, 700, 210);

        // A third brand's credit note. Signed, so a leak shows up as a figure
        // that is too small as readily as one that is too large.
        $this->zeile($this->rechnung([
            'brand_id' => $this->borealis,
            'kind' => Invoice::KIND_CREDIT_NOTE,
            'number' => 'BO2026-08-001',
            'issued_at' => '2026-08-15 10:00:00',
            'buyer_country' => 'DE',
            'net_cent' => 500,
            'tax_cent' => 95,
            'gross_cent' => 595,
        ]), 500, 1900, 95);

        // A fourth brand that trades only in francs. Nothing of it belongs in a
        // euro figure, and — see the currency test below — its own figures must
        // not be reported in euros either.
        $this->zeile($this->rechnung([
            'brand_id' => $this->helvetia,
            'number' => 'HE2026-08-001',
            'currency' => 'CHF',
            'issued_at' => '2026-08-16 10:00:00',
            'buyer_country' => 'CH',
            'net_cent' => 7000,
            'tax_cent' => 0,
            'gross_cent' => 7000,
        ]), 7000, 0, 0);
    }

    /** @param  array<string, mixed>  $werte */
    protected function rechnung(array $werte = []): Invoice
    {
        return Invoice::create(array_merge([
            'number' => 'RE2026-08-'.uniqid(),
            'kind' => Invoice::KIND_INVOICE,
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => 'Bärbel Öztürk-Weiß',
            'buyer_country' => 'DE',
            'net_cent' => 1000,
            'tax_cent' => 190,
            'gross_cent' => 1190,
        ], $werte));
    }

    protected function zeile(Invoice $rechnung, int $nettoCent, int $satzBp, int $steuerCent): InvoiceItem
    {
        return InvoiceItem::whileWriting(fn () => $rechnung->items()->create([
            'name' => 'Eine Position',
            'quantity' => 1,
            'unit_net_cent' => $nettoCent,
            'discount_cent' => 0,
            'net_cent' => $nettoCent,
            'tax_rate_bp' => $satzBp,
            'tax_cent' => $steuerCent,
            'gross_cent' => $nettoCent + $steuerCent,
        ]));
    }

    /** The ten days the fixture lives in, bucketed by day. */
    protected function frage(array $filter = [], string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
            $bucket,
            $filter,
        );
    }

    /** @return array<string|int, int|float> */
    protected function nachSchluessel(array $zeilen): array
    {
        $keyed = [];

        foreach ($zeilen as $zeile) {
            $keyed[$zeile['key'] ?? ''] = $zeile['value'];
        }

        return $keyed;
    }

    // -- The figure, the chart and the splits -------------------------------

    /**
     * With a brand chosen, every one of the four figures counts only its rows.
     *
     * The four together rather than one apiece: they are read side by side, and
     * a leak that reached the money but not the count would be the exact shape
     * of the demo's report.
     */
    #[Test]
    public function a_figure_holds_to_the_brand_that_is_current(): void
    {
        $this->fixture();

        app('brand-context')->setCurrent($this->nordlicht);

        $frage = $this->frage();

        $this->assertSame(1, (new Issued)->value($frage), 'five documents were issued; one of them is Nordlicht’s');
        $this->assertSame(1000, (new Net)->value($frage), 'across the brands this is 5500');
        $this->assertSame(190, (new Tax)->value($frage), 'across the brands this is 685');
        $this->assertSame(1190, (new Gross)->value($frage), 'across the brands this is 6185');

        $this->assertSame(
            (new Gross)->value($frage),
            (new Net)->value($frage) + (new Tax)->value($frage),
            'net plus tax has to be gross inside a brand too',
        );
    }

    /**
     * The chart narrows with the figure, and on the same day.
     *
     * The fixture puts each brand's document on its own day, so a series that
     * was not scoped does not merely hold a wrong total — it grows columns on
     * days this brand did nothing at all.
     */
    #[Test]
    public function the_chart_draws_no_column_for_another_brands_day(): void
    {
        $this->fixture();

        app('brand-context')->setCurrent($this->nordlicht);

        $frage = $this->frage();

        $this->assertSame(['2026-08-12' => 1], (new Issued)->series($frage));
        $this->assertSame(['2026-08-12' => 1000], (new Net)->series($frage));
        $this->assertSame(['2026-08-12' => 190], (new Tax)->series($frage));
        $this->assertSame(['2026-08-12' => 1190], (new Gross)->series($frage));
    }

    /**
     * And so does every split, the one over the joined line items included.
     *
     * `kind` and `buyer_country` are splits of `invoices` and inherit the
     * condition from the window. `tax_rate_bp` does not: it is read from
     * `invoice_items` joined back onto the documents, which is a second query
     * that the declaration on the metric never touches. Unscoped it reports
     * 19 % at 475 and 7 % at 210 — two rows, one of them from a brand that is
     * not on the screen — where Nordlicht has a single line at 19 %.
     */
    #[Test]
    public function a_split_shows_no_other_brands_rows_either(): void
    {
        $this->fixture();

        app('brand-context')->setCurrent($this->nordlicht);

        $frage = $this->frage();

        $this->assertSame(
            ['invoice' => 1],
            $this->nachSchluessel((new Issued)->breakdown($frage, 'kind')),
            'the credit note belongs to Borealis',
        );

        $this->assertSame(
            ['DE' => 1000],
            $this->nachSchluessel((new Net)->breakdown($frage, 'buyer_country')),
            'AT and CH are other brands’ buyers',
        );

        $this->assertSame(
            ['1900' => 190],
            $this->nachSchluessel((new Tax)->breakdown($frage, 'tax_rate_bp')),
            'the rate split reads the line items through a join and is filtered by hand',
        );
    }

    /**
     * Another brand, another set of numbers, from the same rows.
     *
     * The mirror image of the test above: if the condition were a constant, or
     * bound once and cached, both brands would report the same thing and every
     * assertion up to here would still pass.
     */
    #[Test]
    public function switching_the_brand_switches_every_figure(): void
    {
        $this->fixture();

        app('brand-context')->setCurrent($this->aurora);

        $frage = $this->frage();

        $this->assertSame(2, (new Issued)->value($frage));
        $this->assertSame(5000, (new Net)->value($frage), '2000 + 3000, and nothing of the other three brands');
        $this->assertSame(590, (new Tax)->value($frage), '380 + 210');

        $this->assertSame(
            ['1900' => 380, '700' => 210],
            $this->nachSchluessel((new Tax)->breakdown($frage, 'tax_rate_bp')),
        );

        // Borealis holds nothing but the credit note, so its figures are
        // negative. A brand whose whole period is a reversal is the case where
        // a leaked positive row is hardest to notice.
        app('brand-context')->setCurrent($this->borealis);

        $this->assertSame(1, (new Issued)->value($this->frage()));
        $this->assertSame(-500, (new Net)->value($this->frage()));
        $this->assertSame(['credit_note' => 1], $this->nachSchluessel((new Issued)->breakdown($this->frage(), 'kind')));
    }

    // -- The brand that has not been resolved -------------------------------

    /**
     * No current brand: no rows, and still a metric.
     *
     * The posture is brand-context's own — fail closed, because an empty report
     * is a question and a report full of somebody else's numbers is a breach.
     * What must **not** happen is the metric disappearing: `available()` answers
     * whether the thing exists at all, and a tile that vanished from a dashboard
     * cannot be understood by the person looking at it.
     */
    #[Test]
    public function an_unresolved_brand_reads_zero_without_the_metric_vanishing(): void
    {
        $this->fixture();

        app('brand-context')->forget();

        $frage = $this->frage();

        foreach ([new Issued, new Net, new Gross, new Tax] as $metrik) {
            $this->assertTrue($metrik->available(), $metrik::class.' left the screen instead of reading zero.');
            $this->assertSame(0, $metrik->value($frage), $metrik::class.' answered for brands nobody had chosen.');
            $this->assertSame([], $metrik->series($frage));
        }

        $this->assertSame([], (new Issued)->breakdown($frage, 'kind'));
        $this->assertSame([], (new Net)->breakdown($frage, 'buyer_country'));
        $this->assertSame([], (new Tax)->breakdown($frage, 'tax_rate_bp'));
    }

    /** An installation that has said "open" gets what it asked for. */
    #[Test]
    public function a_fail_mode_of_open_is_the_installations_own_decision(): void
    {
        $this->fixture();

        config()->set('brand-context.fail_mode', 'open');
        app('brand-context')->forget();

        $this->assertSame(4, (new Issued)->value($this->frage()), 'the four euro documents of every brand');
        $this->assertSame(5500, (new Net)->value($this->frage()));
    }

    // -- The installations that know no brands ------------------------------

    /**
     * Single-brand: not one condition more than the rest of the install applies.
     *
     * This is the half that is easy to get wrong in the other direction. A
     * filter that ran anyway would file every document written before
     * brand-context was installed under a brand it never had, and the figures of
     * an ordinary single-brand Statamic site would quietly go to zero.
     */
    #[Test]
    public function a_single_brand_install_is_not_filtered_at_all(): void
    {
        $this->fixture();

        config()->set('brand-context.multi_brand', false);
        app('brand-context')->forget();

        $this->assertSame(4, (new Issued)->value($this->frage()));
        $this->assertSame(5500, (new Net)->value($this->frage()));
        $this->assertSame(
            ['1900' => 475, '700' => 210],
            $this->nachSchluessel((new Tax)->breakdown($this->frage(), 'tax_rate_bp')),
        );
    }

    /** A cross-brand admin operation is the one place the whole thing steps aside. */
    #[Test]
    public function an_explicit_bypass_reaches_every_brand(): void
    {
        $this->fixture();

        app('brand-context')->setCurrent($this->nordlicht);

        $alle = app('brand-context')->withoutBrandScope(fn () => [
            (new Issued)->value($this->frage()),
            (new Net)->value($this->frage()),
        ]);

        $this->assertSame([4, 5500], $alle);
    }

    // -- Which money a brand's figures are in -------------------------------

    /**
     * The currency a brand trades in, not the one the installation mostly does.
     *
     * `mostUsedCurrency()` reads the same list the filter offers, and that list
     * is a second query the declaration cannot reach. Read across all brands it
     * answers "EUR" — four euro documents against one in francs — and Helvetia,
     * which has never invoiced a euro, would have every tile filtered to a
     * currency it does not use and report zero. A brand with documents,
     * displayed as a brand with none, is the same defect as the leak with its
     * sign turned round.
     */
    #[Test]
    public function a_brands_figures_are_in_the_currency_that_brand_invoices_in(): void
    {
        $this->fixture();

        app('brand-context')->setCurrent($this->helvetia);

        $frage = $this->frage();

        $this->assertSame(
            [['value' => 'CHF', 'label' => 'CHF']],
            (new Net)->filterOptions()['currency'],
            'the switch offers the brand’s own currencies',
        );

        $this->assertSame('CHF', (new Net)->meta($frage)['currency']);
        $this->assertSame(7000, (new Net)->value($frage), 'in euros this brand reads zero');
        $this->assertSame(1, (new Issued)->value($frage));
    }
}
