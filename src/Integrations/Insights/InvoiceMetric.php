<?php

namespace Goldnead\Invoices\Integrations\Insights;

use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicInsights\Contracts\HasFilterOptions;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What every invoice figure has in common.
 *
 * Four decisions shape every number in this directory, and each one is the
 * kind that is invisible once it is wrong:
 *
 * 1. **Everything is dated on `issued_at`.** The issue date is the date an
 *    invoice stands under in every set of books there is; `created_at` is when
 *    the software noticed. A document written on the 1st for a payment taken on
 *    the 30th belongs to the 30th, and a period that used the wrong one would
 *    disagree with the accountant's own total for the month.
 * 2. **A credit note is subtracted.** It carries *positive* amounts in the
 *    database — see {@see InvoiceWriter::creditNoteFor()},
 *    which negates the meaning rather than the sign because the columns are
 *    unsigned and the document says what it is. Adding them up would therefore
 *    count a reversal as a second sale, which is the exact opposite of what it
 *    is. Every money figure here signs them with SQL instead.
 * 3. **One currency at a time.** 100 EUR plus 100 CHF is not 200 of anything.
 *    The condition sits in {@see inPeriod()}, so it applies to the figure, the
 *    chart and every split at once and cannot be forgotten in one of them.
 * 4. **One brand at a time.** Declared once through {@see brandColumn()} and
 *    applied by the base class, so a figure narrows by exactly the rules the
 *    rest of the installation reads by. Two queries in this file do not pass
 *    through that method — the line-item join and the currency options — and
 *    both call {@see TableMetric::brandScoped()} themselves; each says so where
 *    it stands.
 *
 * Read with SQL aggregates rather than through the models: an aggregate is a
 * read, and hydrating ten thousand invoices to add up a column would be slower
 * and no more correct.
 *
 * Nothing here imports anything of the analytics addon's beyond its contract,
 * its base class and its value objects, and these classes are only ever loaded
 * once that sibling has announced itself — see the guard in the ServiceProvider.
 * Hence `suggest` in composer.json rather than `require`.
 */
abstract class InvoiceMetric extends TableMetric implements HasFilterOptions
{
    /**
     * The most-used currency, worked out once per instance.
     *
     * A metric is asked for its value, its series and several splits on one
     * screen, and every one of them needs to know which money it is about.
     * Without this the same `count(*) group by currency` would run five times
     * to produce the same answer.
     */
    private ?string $defaultCurrency = null;

    protected function table(): string
    {
        return 'invoices';
    }

    protected function timestamp(): string
    {
        return 'issued_at';
    }

    /**
     * Every document belongs to exactly one brand, and so does every figure.
     *
     * Declared rather than filtered per query: {@see TableMetric::inPeriod()}
     * then narrows the value, the chart and every split at once, by the same
     * rules `BrandContext\Scopes\BrandScope` applies to every other read in the
     * installation. The column exists on every install of this addon — the
     * migration gives it a default of 0 — so nothing here is conditional on
     * brand-context being present; the base class checks that.
     *
     * Without it the money of every brand was added into one figure while the
     * screen named a single brand: with "Nordlicht" chosen, four documents of
     * three other brands and 257,15 € of their net. It was found on the running
     * demo, not by a test, which is why the two queries below that the
     * declaration cannot reach are now filtered by hand.
     */
    protected function brandColumn(): ?string
    {
        return 'brand_id';
    }

    public function group(): string
    {
        return __('invoices::messages.metric_group');
    }

    /**
     * The rows inside the window, in one currency.
     *
     * Overridden rather than filtered per figure, which is what
     * {@see TableMetric::inPeriod()} asks for: a condition put here reaches the
     * value, the series and every breakdown, and there is no fourth place to
     * forget it.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return parent::inPeriod($query, $column)
            ->where('currency', $this->currencyOf($query));
    }

    /**
     * The line items of those same invoices, windowed the same way.
     *
     * Its own query rather than a relation, and joined the other way round —
     * from the lines onto their documents — because the split it feeds is by a
     * column of the *line*, and the window, the currency and the sign are all
     * properties of the *document*. Every condition of {@see inPeriod()} is
     * repeated here against the qualified column names; a line whose invoice
     * falls outside the window must not reach a figure of it.
     *
     * **The brand among them, and by hand.** {@see brandColumn()} is applied by
     * {@see TableMetric::inPeriod()}, and this query does not go through it —
     * it starts at `invoice_items`, a table that has no brand of its own. So
     * {@see TableMetric::brandScoped()} is called here explicitly. It qualifies
     * the column with {@see table()}, which is `invoices`, and that is exactly
     * the side of the join the brand lives on: the same condition, written in
     * the same place in the same order, so the split cannot narrow differently
     * from the figure it splits. This is the one place in this addon where the
     * declaration is not enough, and it is the shape to look at first if a
     * third such query is ever added.
     */
    protected function itemsInPeriod(MetricQuery $query): Builder
    {
        $period = $query->period;

        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.currency', $this->currencyOf($query))
            ->when($period->from, fn ($rows) => $rows->where('invoices.issued_at', '>=', $period->from))
            // Half-open, the same way `TableMetric::inPeriod()` does it, and for
            // the same reason: a binding formats the upper bound as
            // `Y-m-d H:i:s` and drops the fraction, so against `<=` on a column
            // storing milliseconds the last second of the period falls out —
            // silently, and only on some engines. This query does not inherit
            // that fix because it starts at the items table and joins back, so
            // the condition is spelled out. Two spellings of one window is how
            // the tile and its own split end up disagreeing.
            ->when($period->toExclusive(), fn ($rows) => $rows->where('invoices.issued_at', '<', $period->toExclusive()));

        return $this->brandScoped($rows);
    }

    /**
     * A sum that takes a credit note off again.
     *
     * The whole of decision 2 above, in one expression, so that no figure in
     * this directory can be written without it. `coalesce` because a period with
     * no documents in it sums to null, and a money figure of null would be read
     * as "no answer" when the answer is zero — this addon's `null` is reserved
     * for questions that do not apply.
     *
     * The kind is interpolated from a class constant of this package, never from
     * anything a request carries.
     */
    protected function signedSum(string $column, string $kindColumn = 'kind'): string
    {
        return sprintf(
            "coalesce(sum(case when %s = '%s' then -%s else %s end), 0)",
            $kindColumn,
            Invoice::KIND_CREDIT_NOTE,
            $column,
            $column,
        );
    }

    /**
     * The one currency this question is about.
     *
     * The screen may hand one down. Otherwise it is the one this installation
     * writes most of its invoices in — there is no `currency` key in
     * `config/invoices.php`, because an invoice takes its currency from the
     * payment it was written for and never from a setting. The last fallback is
     * the checkout's own configured currency, which is a hard dependency of this
     * package and therefore always present; it only ever applies on an
     * installation that has not issued a single document, where every figure is
     * zero anyway and the label is all that is being chosen.
     */
    protected function currencyOf(MetricQuery $query): string
    {
        $currency = $query->filter('currency');

        if (is_string($currency) && $currency !== '') {
            return $currency;
        }

        return $this->defaultCurrency ??= $this->mostUsedCurrency();
    }

    protected function mostUsedCurrency(): string
    {
        $options = $this->filterOptions()['currency'] ?? [];

        return $options[0]['value'] ?? (string) config('statamic-payments.currency', 'EUR');
    }

    /**
     * Which currencies this installation has ever invoiced in, busiest first.
     *
     * Ordered by number of documents and not alphabetically: the currency the
     * business actually trades in should be the one already selected, and on a
     * seller that once billed three francs "CHF" would otherwise sort above the
     * euro it lives on.
     *
     * Credit notes are counted along with invoices. A currency somebody had to
     * write a reversal in is a currency this installation trades in, and the
     * question here is which choices exist, not what they came to.
     *
     * Empty when there is nothing to choose between, which the contract
     * distinguishes from not offering the filter at all: no switch is the right
     * screen for a business that has issued nothing.
     *
     * **Of the current brand, which is not decoration.** This list is also where
     * {@see mostUsedCurrency()} takes the currency every figure is then filtered
     * by. Read across all brands, a seller who invoices in francs under one
     * brand and in euros under three others would have every one of the franc
     * brand's tiles default to euros and read zero — a brand with documents,
     * reported as a brand with none. So the second query of this class that the
     * {@see brandColumn()} declaration cannot reach is scoped by hand as well.
     */
    public function filterOptions(): array
    {
        if (! $this->available()) {
            return ['currency' => []];
        }

        $currencies = $this->brandScoped(DB::table($this->table()))
            ->whereNotNull('currency')
            ->groupBy('currency')
            ->orderByRaw('count(*) desc')
            ->pluck('currency')
            ->all();

        return [
            'currency' => array_map(
                fn ($currency) => ['value' => (string) $currency, 'label' => (string) $currency],
                $currencies,
            ),
        ];
    }

    /** The words for a row that has no value in the dimension it is split by. */
    protected function missingLabel(string $dimension): string
    {
        return __('invoices::messages.metric_no_'.$dimension);
    }
}
