<?php

namespace Goldnead\Invoices\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * One money column of the invoices in a period.
 *
 * Net, gross and tax are the same question asked of three columns, so they are
 * one implementation and three names rather than three copies of a query that
 * has to agree with itself: on sound data net plus tax is gross, and a figure
 * that drifted because somebody edited one of three near-identical classes is
 * the failure this shape rules out.
 *
 * Every subclass is a {@see Unit::CURRENCY} figure and therefore in **minor
 * units, as an integer** — never a float of euros. The sign of a credit note
 * and the single currency both come from {@see InvoiceMetric}.
 */
abstract class AmountMetric extends InvoiceMetric implements HasBreakdowns
{
    /** Which column of the document this figure adds up. */
    abstract protected function amountColumn(): string;

    public function unit(): string
    {
        return Unit::CURRENCY;
    }

    /** The formatter cannot print money without knowing which money. */
    public function meta(MetricQuery $query): array
    {
        return ['currency' => $this->currencyOf($query)];
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $summe = $this->inPeriod($query)
            ->selectRaw($this->signedSum($this->amountColumn()).' as measured')
            ->first();

        return (int) ($summe->measured ?? 0);
    }

    /**
     * The same figure per bucket, sorted.
     *
     * A bucket holding nothing but a credit note stays in, with a negative
     * value. It is a day on which a document was written, and dropping it would
     * hide the one kind of day a reader goes looking for.
     *
     * Sorted here rather than left to the driver: `group by` makes no promise
     * about order, and a chart drawn in the order SQLite happened to return is
     * a chart that changes shape on MySQL.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $buckets = array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->inPeriod($query), $query, $this->signedSum($this->amountColumn())),
        );

        ksort($buckets);

        return $buckets;
    }

    public function breakdowns(): array
    {
        return [
            'buyer_country' => __('invoices::messages.metric_breakdown_buyer_country'),
        ];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'buyer_country') {
            return [];
        }

        // A document with no country is a row keyed null, never a dropped one:
        // the rows have to add up to the figure they split, and a split that
        // quietly excludes some of them disagrees with its own total while
        // every column in it still looks right.
        return $this->labelled(
            $this->splitByColumn(
                $this->inPeriod($query),
                $query,
                'buyer_country',
                $this->signedSum($this->amountColumn()),
                $limit,
            ),
            'buyer_country',
        );
    }
}
