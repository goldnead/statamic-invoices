<?php

namespace Goldnead\Invoices\Integrations\Insights;

/**
 * What was invoiced before tax, less what credit notes took back.
 *
 * The figure a profit-and-loss account is built from, which is why it is here
 * beside the gross one rather than left to be worked out by subtraction on the
 * screen: net and tax are stored per document because a single order can carry
 * two rates, and re-deriving either of them from the other would produce a
 * number that disagrees with the documents by a cent per line.
 */
class Net extends AmountMetric
{
    public function handle(): string
    {
        return 'invoices.net';
    }

    public function label(): string
    {
        return __('invoices::messages.metric_net');
    }

    public function description(): ?string
    {
        return __('invoices::messages.metric_net_description');
    }

    protected function amountColumn(): string
    {
        return 'net_cent';
    }
}
