<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Integrations\Insights\Gross;
use Goldnead\Invoices\Integrations\Insights\InvoiceMetric;
use Goldnead\Invoices\Integrations\Insights\Issued;
use Goldnead\Invoices\Integrations\Insights\Net;
use Goldnead\Invoices\Integrations\Insights\Tax;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\TableMetric;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The four numbers this addon offers the analytics addon.
 *
 * Every expectation below is worked out by hand from one small fixture,
 * following the three rules the integration is built on: everything is dated on
 * `issued_at`, a credit note is subtracted, and one currency at a time. A query
 * that drifted shows up here as an arithmetic disagreement rather than as a
 * green suite over a different report.
 *
 * The fixture is deliberately built so that **net plus tax equals gross** for
 * the period. Three figures that have to agree with each other are three
 * chances to notice a sign that went the wrong way, and the credit note is the
 * only place a sign can go wrong.
 *
 * Tested against stand-ins for the sibling rather than the real package,
 * because the sibling is optional and a test that needed it installed would be
 * proving the opposite of what this addon claims. See
 * `tests/Fakes/insights-contracts.php` for why those are required files and not
 * autoload entries, and `InsightsContractsMatchTest` for what holds them to
 * their claim.
 *
 * Time is frozen. The buckets are asserted as literal dates, and a suite that
 * ran across midnight would otherwise fail once a night for reasons that have
 * nothing to do with the code.
 */
class InsightsMetricsTest extends TestCase
{
    /** The day everything below is measured from. */
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Collects what the service provider registers. */
    protected object $insights;

    protected function setUp(): void
    {
        // Before the application exists, all three of them. The contracts have
        // to be there before a metric class is loaded, the base class before
        // one is *declared*, and the facade before the provider's `booted()`
        // callback asks whether it is — a callback that has already run cannot
        // be given a second chance.
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        if (! class_exists(TableMetric::class)) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Stricter than the real manager on purpose.
             *
             * The genuine one accepts a metric without a handle and works one
             * out by constructing it. Accepting that here would let the
             * provider drop the handle and still look correct — and the handle
             * is the half that ends up in saved dashboards and URLs.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- The fixture --------------------------------------------------------

    /**
     * Three invoices, one credit note, one older document, one in another currency.
     *
     * Small enough to add up in the head, and every awkward case is in it: a
     * buyer with no country, an invoice reversed inside the same period, two
     * tax rates on one document, a rate with a decimal in it, a zero rate, a
     * document dated before the window, and a second currency.
     *
     * In euros, inside the window: net 1000 + 2000 + 500 − 1000 = 2500, tax
     * 190 + 140 + 19 − 190 = 159, gross 1190 + 2140 + 519 − 1190 = 2659, and
     * four documents.
     */
    protected function fixture(): void
    {
        $eins = $this->rechnung([
            'number' => 'RE2026-08-001',
            'issued_at' => '2026-08-15 10:00:00',
            'buyer_country' => 'DE',
            'net_cent' => 1000,
            'tax_cent' => 190,
            'gross_cent' => 1190,
        ]);

        $this->zeile($eins, 1000, 1900, 190);

        $this->zeile($zwei = $this->rechnung([
            'number' => 'RE2026-08-002',
            'issued_at' => '2026-08-15 18:00:00',
            'buyer_country' => 'AT',
            'net_cent' => 2000,
            'tax_cent' => 140,
            'gross_cent' => 2140,
        ]), 2000, 700, 140);

        $this->assertNotNull($zwei->getKey());

        // No country. A row in every split, never an omission — and two rates on
        // one document, which is the case a single rate per invoice could not
        // express and the reason the rate split reads the lines.
        $drei = $this->rechnung([
            'number' => 'RE2026-08-003',
            'issued_at' => '2026-08-18 09:00:00',
            'buyer_country' => null,
            'net_cent' => 500,
            'tax_cent' => 19,
            'gross_cent' => 519,
        ]);

        $this->zeile($drei, 250, 750, 19);
        $this->zeile($drei, 250, 0, 0);

        // The reversal of the first one, four days later. Its amounts are
        // positive in the database and have to come off every figure.
        $storno = $this->rechnung([
            'number' => 'RE2026-08-004',
            'kind' => Invoice::KIND_CREDIT_NOTE,
            'reverses_invoice_id' => $eins->getKey(),
            'issued_at' => '2026-08-19 08:00:00',
            'buyer_country' => 'DE',
            'net_cent' => 1000,
            'tax_cent' => 190,
            'gross_cent' => 1190,
        ]);

        $this->zeile($storno, 1000, 1900, 190);

        // Before the window. Must not appear in a single figure.
        $this->zeile($this->rechnung([
            'number' => 'RE2026-07-001',
            'issued_at' => '2026-07-02 10:00:00',
            'buyer_country' => 'DE',
            'net_cent' => 9900,
            'tax_cent' => 1881,
            'gross_cent' => 11781,
        ]), 9900, 1900, 1881);

        // Francs. Real money, and none of it belongs in a euro figure.
        $this->zeile($this->rechnung([
            'number' => 'RE2026-08-005',
            'issued_at' => '2026-08-17 11:00:00',
            'currency' => 'CHF',
            'buyer_country' => 'CH',
            'net_cent' => 5000,
            'tax_cent' => 0,
            'gross_cent' => 5000,
        ]), 5000, 0, 0);
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

    /**
     * One line, written the only way the model allows.
     *
     * `whileWriting` is not a convenience here: a line cannot be added to an
     * invoice on its own, because the totals above it would stay as they are
     * and the document would read as correct while it is not.
     */
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

    // -- The four numbers ---------------------------------------------------

    /**
     * Every figure at once, against hand-worked totals.
     *
     * One test rather than four, deliberately: they are read side by side on a
     * screen and have to agree with each other. Net plus tax is gross, and a
     * net that changed without the gross following it is the failure worth
     * catching — four separate tests are four chances to fix one of them and
     * leave the rest.
     */
    #[Test]
    public function the_four_figures_match_what_the_documents_say(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(4, (new Issued)->value($frage), 'documents: three invoices and the credit note');
        $this->assertSame(2500, (new Net)->value($frage), 'net: 1000 + 2000 + 500 − 1000');
        $this->assertSame(159, (new Tax)->value($frage), 'tax: 190 + 140 + 19 − 190');
        $this->assertSame(2659, (new Gross)->value($frage), 'gross: 1190 + 2140 + 519 − 1190');

        // The one check that makes the three more than three separate sums.
        $this->assertSame(
            (new Gross)->value($frage),
            (new Net)->value($frage) + (new Tax)->value($frage),
            'net plus tax has to be gross, or one of the three signs went the wrong way',
        );
    }

    /** The handles are a contract. They end up in saved dashboards and in URLs. */
    #[Test]
    public function the_handles_and_units_are_the_ones_that_were_promised(): void
    {
        $erwartet = [
            [Issued::class, 'invoices.issued', Unit::COUNT],
            [Net::class, 'invoices.net', Unit::CURRENCY],
            [Gross::class, 'invoices.gross', Unit::CURRENCY],
            [Tax::class, 'invoices.tax', Unit::CURRENCY],
        ];

        foreach ($erwartet as [$klasse, $handle, $unit]) {
            /** @var InvoiceMetric $metrik */
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame($unit, $metrik->unit());
            $this->assertSame(__('invoices::messages.metric_group'), $metrik->group());
            $this->assertNotSame('', $metrik->label());
            $this->assertNotEmpty($metrik->description());

            // The formatter cannot print money without knowing which money. A
            // count needs nothing beyond its unit.
            $this->assertSame(
                $unit === Unit::CURRENCY ? ['currency' => 'EUR'] : [],
                $metrik->meta($this->frage()),
            );
        }
    }

    /**
     * The words are words, not keys.
     *
     * This package had no translation namespace at all before these figures
     * existed, so the registration is new — and a namespace that is not
     * registered fails by printing `invoices::messages.metric_group` on the
     * dashboard rather than by throwing.
     */
    #[Test]
    public function the_labels_are_translated_rather_than_printed_as_keys(): void
    {
        $this->assertSame('Invoices', __('invoices::messages.metric_group'));
        $this->assertSame('Country', __('invoices::messages.metric_breakdown_buyer_country'));
        $this->assertSame('No country', __('invoices::messages.metric_no_buyer_country'));

        $this->app->setLocale('de');

        $this->assertSame('Rechnungen', __('invoices::messages.metric_group'));
        $this->assertSame('Ohne Land', __('invoices::messages.metric_no_buyer_country'));
    }

    // -- Nothing to measure -------------------------------------------------

    /**
     * No tables, no answer — and not a zero.
     *
     * "Nothing to measure" and "measured nothing" are different statements, and
     * a zero for the first is the quiet kind of wrong: it puts a confident 0 €
     * on a dashboard for a site that has not installed this addon at all.
     */
    #[Test]
    public function a_metric_cannot_answer_without_the_tables(): void
    {
        $this->assertTrue((new Net)->available());

        // A second, empty database rather than dropping the tables in this one.
        // Dropping them would leave the suite unable to roll its own migrations
        // back, and a test that breaks its neighbours' teardown reports the
        // wrong failure everywhere afterwards.
        config()->set('database.connections.ohne_rechnungen', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_rechnungen');
        DB::setDefaultConnection('ohne_rechnungen');

        try {
            foreach ([Issued::class, Net::class, Gross::class, Tax::class] as $klasse) {
                $metrik = new $klasse;

                $this->assertFalse($metrik->available(), $klasse.' answered without an invoices table.');
                $this->assertNull($metrik->value($this->frage()), $klasse.' put a number on a screen with no table behind it.');
                $this->assertSame([], $metrik->series($this->frage()));
                $this->assertSame(['currency' => []], $metrik->filterOptions());
            }
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    // -- One currency at a time ---------------------------------------------

    /**
     * 100 EUR plus 100 CHF is not 200 of anything.
     *
     * The fixture holds 2500 net in euros and 5000 in francs. A sum of 7500
     * would be a number with no meaning that no set of books anywhere agrees
     * with, and it is the failure a single missing `where` produces.
     */
    #[Test]
    public function two_currencies_are_never_added_together(): void
    {
        $this->fixture();

        $this->assertSame(2500, (new Net)->value($this->frage()));
        $this->assertSame(2659, (new Gross)->value($this->frage()));
        $this->assertSame(4, (new Issued)->value($this->frage()));

        $inFranken = $this->frage(['currency' => 'CHF']);

        $this->assertSame(5000, (new Net)->value($inFranken));
        $this->assertSame(5000, (new Gross)->value($inFranken));
        $this->assertSame(0, (new Tax)->value($inFranken));
        $this->assertSame(1, (new Issued)->value($inFranken));

        // And the split of a franc figure holds no euros either.
        $this->assertSame(
            ['CH' => 5000],
            $this->nachSchluessel((new Net)->breakdown($inFranken, 'buyer_country')),
        );
    }

    /** The screen may hand a currency down, and the meta has to follow it. */
    #[Test]
    public function the_currency_in_the_question_reaches_the_formatter(): void
    {
        $this->fixture();

        $this->assertSame('CHF', (new Net)->meta($this->frage(['currency' => 'CHF']))['currency']);
        $this->assertSame('EUR', (new Net)->meta($this->frage())['currency']);
    }

    /**
     * Which currencies exist here, busiest first.
     *
     * Not alphabetically: five euro documents against one in francs, and CHF
     * sorts before EUR in the alphabet. A screen that opened on the currency a
     * business billed once and never again would show an almost empty dashboard
     * as its front page — and it is the same list for all four figures, because
     * the question is the same for all four.
     */
    #[Test]
    public function the_currencies_on_offer_are_ordered_by_how_much_was_invoiced_in_them(): void
    {
        $this->fixture();

        $this->assertSame(
            ['currency' => [
                ['value' => 'EUR', 'label' => 'EUR'],
                ['value' => 'CHF', 'label' => 'CHF'],
            ]],
            (new Net)->filterOptions(),
        );

        foreach ([Issued::class, Gross::class, Tax::class] as $klasse) {
            $this->assertSame(
                (new Net)->filterOptions(),
                (new $klasse)->filterOptions(),
                $klasse.' offers a different choice than its siblings.',
            );
        }
    }

    /** Nothing invoiced, nothing to choose between — and no switch on the screen. */
    #[Test]
    public function there_is_no_currency_to_choose_from_before_the_first_document(): void
    {
        $this->assertSame(['currency' => []], (new Net)->filterOptions());

        // And the figures still answer, because "nothing was invoiced" is an
        // answer to what they ask.
        $this->assertSame(0, (new Net)->value($this->frage()));
        $this->assertSame(0, (new Issued)->value($this->frage()));
        $this->assertSame([], (new Net)->series($this->frage()));
    }

    // -- The credit note ----------------------------------------------------

    /**
     * A reversal comes off the money and counts towards the documents.
     *
     * The two rules pull in opposite directions on purpose, and this is the
     * test that pins both: the credit note carries positive amounts in the
     * database — the writer negates the meaning, not the sign — so a money
     * figure that added it would count a reversal as a second sale. The
     * document count is the other way round: a credit note used up a number out
     * of the same gapless series, and a month with three invoices and three
     * reversals is a month with six numbers gone.
     */
    #[Test]
    public function a_credit_note_is_subtracted_from_the_money_and_added_to_the_count(): void
    {
        $this->fixture();
        $frage = $this->frage();

        // Without the sign this would be 3500 net, 349 tax, 3849 gross.
        $this->assertSame(2500, (new Net)->value($frage));
        $this->assertSame(159, (new Tax)->value($frage));
        $this->assertSame(2659, (new Gross)->value($frage));

        $this->assertSame(4, (new Issued)->value($frage));

        $this->assertSame(
            ['invoice' => 3, 'credit_note' => 1],
            $this->nachSchluessel((new Issued)->breakdown($frage, 'kind')),
        );

        // And the kinds are named rather than handed over as handles.
        $zeilen = (new Issued)->breakdown($frage, 'kind');

        $this->assertSame(__('invoices::messages.metric_kind_invoice'), $zeilen[0]['label']);
        $this->assertSame(__('invoices::messages.metric_kind_credit_note'), $zeilen[1]['label']);
    }

    // -- The splits ---------------------------------------------------------

    /**
     * A document without a country is a row keyed null, not a missing row.
     *
     * A report that quietly excludes rows is the hardest kind of wrong to
     * notice: the columns still add up among themselves, and only the total
     * disagrees — which is the number nobody re-adds. The check at the end is
     * the one that catches it.
     */
    #[Test]
    public function an_invoice_without_a_country_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $zeilen = (new Net)->breakdown($this->frage(), 'buyer_country');

        $this->assertCount(3, $zeilen);

        // Largest first: Austria 2000, the country-less document 500, and
        // Germany nets to nothing because its invoice was reversed.
        $this->assertSame('AT', $zeilen[0]['key']);
        $this->assertSame(2000, $zeilen[0]['value']);

        $this->assertNull($zeilen[1]['key']);
        $this->assertSame(500, $zeilen[1]['value']);
        $this->assertSame(__('invoices::messages.metric_no_buyer_country'), $zeilen[1]['label']);

        $this->assertSame('DE', $zeilen[2]['key']);
        $this->assertSame(0, $zeilen[2]['value'], 'reversed in the same period, and still a row');

        $this->assertSame(2500, array_sum(array_column($zeilen, 'value')), 'the split has to add up to the figure it splits');
    }

    /** The gross split is the same shape and adds up to its own figure. */
    #[Test]
    public function the_gross_split_adds_up_too(): void
    {
        $this->fixture();

        $zeilen = (new Gross)->breakdown($this->frage(), 'buyer_country');

        $this->assertSame(['AT' => 2140, '' => 519, 'DE' => 0], $this->nachSchluessel($zeilen));
        $this->assertSame(2659, array_sum(array_column($zeilen, 'value')));
    }

    /**
     * The tax, split by the rate that produced it.
     *
     * Read from the lines, because a rate is a property of a line: the
     * country-less document carries 7.5 % on half of itself and nothing on the
     * other half, which is exactly what a document-level rate could not say.
     * The reversed 19 % nets to zero and stays a row — the rate was charged and
     * then taken back, and a screen that showed nothing there could not tell
     * that apart from a rate nobody ever used.
     */
    #[Test]
    public function the_tax_splits_by_the_rate_on_the_line(): void
    {
        $this->fixture();

        $zeilen = (new Tax)->breakdown($this->frage(), 'tax_rate_bp');

        $this->assertSame(
            ['700' => 140, '750' => 19, '1900' => 0, '0' => 0],
            $this->nachSchluessel($zeilen),
        );

        $this->assertSame(159, array_sum(array_column($zeilen, 'value')), 'the rates have to add up to the tax figure');

        // Largest first, and basis points read as a person reads them.
        $this->assertSame('700', $zeilen[0]['key']);
        $this->assertSame(__('invoices::messages.metric_tax_rate', ['rate' => '7']), $zeilen[0]['label']);

        $this->assertSame('750', $zeilen[1]['key']);
        $this->assertSame(__('invoices::messages.metric_tax_rate', ['rate' => '7.5']), $zeilen[1]['label']);

        // The literal strings, so a change to the formatting is visible here and
        // not only in a translation file.
        $this->assertSame('7 %', $zeilen[0]['label']);
        $this->assertSame('7.5 %', $zeilen[1]['label'], 'trailing zeros go; 750 basis points are 7.5 %');

        $beschriftungen = array_column($zeilen, 'label', 'key');

        $this->assertSame('19 %', $beschriftungen['1900']);
        $this->assertSame('0 %', $beschriftungen['0'], 'a zero rate is a rate, not a missing one');
    }

    /** A split nobody offers is empty, not an error. */
    #[Test]
    public function an_unknown_split_is_empty(): void
    {
        $this->fixture();

        $this->assertSame([], (new Net)->breakdown($this->frage(), 'weather'));
        $this->assertSame([], (new Net)->breakdown($this->frage(), 'tax_rate_bp'), 'only the tax figure splits by rate');
        $this->assertSame([], (new Issued)->breakdown($this->frage(), 'buyer_country'));

        $this->assertSame(['buyer_country'], array_keys((new Net)->breakdowns()));
        $this->assertSame(['buyer_country', 'tax_rate_bp'], array_keys((new Tax)->breakdowns()));
        $this->assertSame(['kind'], array_keys((new Issued)->breakdowns()));
    }

    /** Largest first, and no more than asked for. */
    #[Test]
    public function a_split_is_ordered_by_size_and_respects_the_limit(): void
    {
        $this->fixture();

        $zeilen = (new Net)->breakdown($this->frage(), 'buyer_country', 2);

        $this->assertCount(2, $zeilen);
        $this->assertSame(['AT', null], array_column($zeilen, 'key'));

        $this->assertCount(1, (new Tax)->breakdown($this->frage(), 'tax_rate_bp', 1));
    }

    /**
     * No line table, no rate split — rather than every euro reported as unrated.
     *
     * The figure itself is unaffected: it never needed the lines.
     */
    #[Test]
    public function the_rate_split_is_absent_without_the_line_table(): void
    {
        $this->fixture();

        // Renamed rather than dropped, and put back afterwards. A test that
        // destroys a table leaves the suite unable to roll its own migrations
        // back, and every later failure then points at the wrong place.
        Schema::rename('invoice_items', 'invoice_items_beiseite');

        try {
            $this->assertSame([], (new Tax)->breakdown($this->frage(), 'tax_rate_bp'));
            $this->assertSame(159, (new Tax)->value($this->frage()));
        } finally {
            Schema::rename('invoice_items_beiseite', 'invoice_items');
        }
    }

    // -- Over time ----------------------------------------------------------

    /**
     * Only the buckets that have something in them.
     *
     * The empty days are the analytics addon's job — it fills the range for
     * every metric at once. A metric that filled its own would be filled twice,
     * and one that invented a bucket outside the range would draw a column the
     * axis has no place for.
     *
     * The 19th is the interesting one: nothing was invoiced that day and a
     * reversal was written, so the bucket is negative. It is a day on which a
     * document was issued, and dropping it would hide the one kind of day a
     * reader goes looking for.
     */
    #[Test]
    public function a_series_returns_only_the_buckets_that_have_data(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(
            ['2026-08-15' => 3000, '2026-08-18' => 500, '2026-08-19' => -1000],
            (new Net)->series($frage),
        );

        $this->assertSame(
            ['2026-08-15' => 3330, '2026-08-18' => 519, '2026-08-19' => -1190],
            (new Gross)->series($frage),
        );

        $this->assertSame(
            ['2026-08-15' => 330, '2026-08-18' => 19, '2026-08-19' => -190],
            (new Tax)->series($frage),
        );

        $this->assertSame(
            ['2026-08-15' => 2, '2026-08-18' => 1, '2026-08-19' => 1],
            (new Issued)->series($frage),
            'a credit note is a document on the day it was written',
        );

        // The document from July is in none of them.
        $this->assertSame(2500, array_sum((new Net)->series($frage)));
    }

    /**
     * The grain comes from the question, not from the period.
     *
     * The analytics addon has already decided and put it in the query. A metric
     * that worked it out again from the period length could disagree with the
     * axis it is drawn on.
     */
    #[Test]
    public function a_monthly_question_gets_monthly_buckets(): void
    {
        $this->fixture();

        $this->assertSame(
            ['2026-08' => 2500],
            (new Net)->series($this->frage([], MetricQuery::BUCKET_MONTH)),
        );

        $this->assertSame(
            ['2026-08' => 4],
            (new Issued)->series($this->frage([], MetricQuery::BUCKET_MONTH)),
        );
    }

    /**
     * An open-ended period windows nothing, and the currency has to carry alone.
     *
     * `Period::fromPreset('all')` leaves both bounds null, so
     * {@see TableMetric::inPeriod()} adds no
     * date condition at all — every row of the table is in scope, the July
     * document included. That is correct here only because `issued_at` is NOT
     * NULL: on a nullable timestamp the same code would sweep in rows that have
     * no date and therefore belong to no period, which is a thing to know
     * before somebody adds such a column.
     *
     * It is also the one question where the currency `where` is the only
     * condition standing between a euro figure and a franc one.
     */
    #[Test]
    public function an_open_ended_period_still_holds_to_one_currency(): void
    {
        $this->fixture();

        $alles = new MetricQuery(Period::fromPreset('all'));

        $this->assertTrue($alles->period->isOpenEnded());

        // Everything ever invoiced in euros, July included: 12400 net.
        $this->assertSame(12400, (new Net)->value($alles), 'net: 2500 in the window plus 9900 from July');
        $this->assertSame(2040, (new Tax)->value($alles));
        $this->assertSame(14440, (new Gross)->value($alles));
        $this->assertSame(5, (new Issued)->value($alles));

        $this->assertSame(
            (new Gross)->value($alles),
            (new Net)->value($alles) + (new Tax)->value($alles),
        );

        // And the francs are still their own money.
        $this->assertSame(5000, (new Net)->value($alles->with('currency', 'CHF')));
    }

    // -- The wiring ---------------------------------------------------------

    /**
     * The provider hands all four to the sibling, lazily and by handle.
     *
     * By class name rather than instance, so booting this addon does not build
     * four metric objects on a request that renders none of them.
     */
    #[Test]
    public function the_service_provider_offers_every_metric_to_the_sibling(): void
    {
        $this->assertSame([
            'invoices.issued' => Issued::class,
            'invoices.net' => Net::class,
            'invoices.gross' => Gross::class,
            'invoices.tax' => Tax::class,
        ], $this->insights->registered);
    }
}
