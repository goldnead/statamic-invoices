<?php

namespace Goldnead\Invoices\Integrations\Insights;

use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT shown on this period's documents, less what credit notes took back.
 *
 * **The only place in the family that has this number.** The checkout knows
 * what was charged; only an invoice knows how much of it was tax and under
 * which rule — that is the whole reason this addon exists, and it is why the
 * figure is contributed from here rather than from `statamic-payments`, which
 * has no `tax_cent` to offer.
 *
 * The split by rate is the question behind the figure: a single total says what
 * has to be declared, and the rates say which lines of the return it belongs
 * on. It is read from the line items, because a rate is a property of a line —
 * sheet music at 7 % beside a course at 19 % is one document and two rates, and
 * a document-level rate could not express it.
 */
class Tax extends AmountMetric
{
    public function handle(): string
    {
        return 'invoices.tax';
    }

    public function label(): string
    {
        return __('invoices::messages.metric_tax');
    }

    public function description(): ?string
    {
        return __('invoices::messages.metric_tax_description');
    }

    protected function amountColumn(): string
    {
        return 'tax_cent';
    }

    public function breakdowns(): array
    {
        return parent::breakdowns() + [
            'tax_rate_bp' => __('invoices::messages.metric_breakdown_tax_rate_bp'),
        ];
    }

    /**
     * By country as its siblings do, and additionally by the rate itself.
     *
     * The rate split runs over `invoice_items`, joined back onto the documents
     * so that the window, the currency and the sign are the ones the figure
     * uses. Without the join a line of an invoice from last year would land in
     * this month's rates, and the split would not add up to the number above it.
     *
     * Empty rather than wrong when the line table is absent: that is a
     * half-installed package, and a rate breakdown built from nothing would
     * report every euro of tax as unrated.
     */
    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if ($dimension !== 'tax_rate_bp') {
            return parent::breakdown($query, $dimension, $limit);
        }

        if (! $this->available() || ! Schema::hasTable('invoice_items')) {
            return [];
        }

        $zeilen = $this->splitByColumn(
            $this->itemsInPeriod($query),
            $query,
            'invoice_items.tax_rate_bp',
            $this->signedSum('invoice_items.tax_cent', 'invoices.kind'),
            $limit,
        );

        return array_map(fn (array $zeile) => [
            'key' => $zeile['key'],
            'label' => $zeile['key'] === null
                ? $this->missingLabel('tax_rate_bp')
                : $this->rateLabel($zeile['key']),
            'value' => $zeile['value'],
        ], $zeilen);
    }

    /**
     * Basis points as a person reads them: 1900 becomes "19 %".
     *
     * Trailing zeros go, so 750 is "7.5 %" and not "7.50 %" — the same trim
     * {@see InvoiceItem::ratePercent()} makes for the
     * printed document. The decimal point differs from it deliberately: that
     * one formats a German invoice and writes "7,5", this one is a chart label
     * read beside figures from every other addon on the dashboard, and a label
     * that changes its punctuation with the reader's language cannot be
     * compared across a screen.
     *
     * Zero is a rate, not a missing one: an exempt line is taxed at 0 % for a
     * stated reason, and calling it "no rate" would hide the exemption the
     * document exists to declare.
     */
    protected function rateLabel(string $basispunkte): string
    {
        $satz = rtrim(rtrim(number_format((int) $basispunkte / 100, 2, '.', ''), '0'), '.');

        return __('invoices::messages.metric_tax_rate', ['rate' => $satz]);
    }
}
