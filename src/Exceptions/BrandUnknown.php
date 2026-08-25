<?php

namespace Goldnead\Invoices\Exceptions;

use Goldnead\StatamicPayments\Models\Payment;

/**
 * A multi-brand installation, and no brand is current.
 *
 * `currentId()` would answer with the default brand, and that is exactly what
 * must not happen here: an invoice belongs to a number series, one series per
 * brand, and a second brand's document landing silently in the first brand's
 * series is unfixable a moment later — the row is immutable.
 *
 * A brand is not recoverable from the payment either; `statamic-payments` does
 * not scope by one. So the honest answer is to refuse and let somebody say
 * which brand sold this, through `BrandContext::runFor()`.
 */
class BrandUnknown extends InvoiceNotWritten
{
    public function __construct(public readonly Payment $payment)
    {
        parent::__construct(
            "No brand is current, so payment {$payment->getKey()} has no number series to belong to. "
            .'Wrap the call in BrandContext::runFor() — falling back to the default brand would put '
            .'this invoice in another brand\'s series, permanently.'
        );
    }
}
