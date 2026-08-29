<?php

namespace Goldnead\Invoices\Integrations\Insights;

/**
 * What was invoiced in total, less what credit notes took back.
 *
 * The amount the buyer was actually asked for. On sound data it is net plus
 * tax, and it is stored rather than computed for the same reason those two are:
 * the document is the evidence, and a screen that re-adds it could disagree
 * with what is printed on the paper.
 */
class Gross extends AmountMetric
{
    public function handle(): string
    {
        return 'invoices.gross';
    }

    public function label(): string
    {
        return __('invoices::messages.metric_gross');
    }

    public function description(): ?string
    {
        return __('invoices::messages.metric_gross_description');
    }

    protected function amountColumn(): string
    {
        return 'gross_cent';
    }
}
