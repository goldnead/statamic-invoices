<?php

namespace Goldnead\Invoices\Integrations\Insights;

use Goldnead\Invoices\Models\Invoice;
use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many documents were issued.
 *
 * **A credit note counts as one, positively** — which is the opposite of what
 * the money figures beside it do, and deliberately so. This is a count of
 * documents, and a reversal is a document: it has its own number out of the
 * same gapless series, and a month in which three invoices and three credit
 * notes were written is a month with six numbers used up, not a month with
 * none. The `kind` split is what makes that readable instead of surprising, and
 * it is why the split is offered at all.
 */
class Issued extends InvoiceMetric implements HasBreakdowns
{
    public function handle(): string
    {
        return 'invoices.issued';
    }

    public function label(): string
    {
        return __('invoices::messages.metric_issued');
    }

    public function description(): ?string
    {
        return __('invoices::messages.metric_issued_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->inPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $buckets = array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->inPeriod($query), $query, 'count(*)'),
        );

        ksort($buckets);

        return $buckets;
    }

    public function breakdowns(): array
    {
        return [
            'kind' => __('invoices::messages.metric_breakdown_kind'),
        ];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || $dimension !== 'kind') {
            return [];
        }

        $zeilen = $this->splitByColumn(
            $this->inPeriod($query),
            $query,
            'kind',
            'count(*)',
            $limit,
        );

        return array_map(fn (array $zeile) => [
            'key' => $zeile['key'],
            'label' => $this->kindLabel($zeile['key']),
            'value' => $zeile['value'],
        ], $zeilen);
    }

    /**
     * What to call a kind of document.
     *
     * A kind this package does not know keeps its raw value rather than
     * vanishing behind "no kind": the column is a plain string so that a later
     * document type does not need a migration, and a row labelled with its own
     * handle is readable, while a row that disappeared is not.
     */
    protected function kindLabel(?string $kind): string
    {
        return match ($kind) {
            Invoice::KIND_INVOICE => __('invoices::messages.metric_kind_invoice'),
            Invoice::KIND_CREDIT_NOTE => __('invoices::messages.metric_kind_credit_note'),
            null => $this->missingLabel('kind'),
            default => $kind,
        };
    }
}
