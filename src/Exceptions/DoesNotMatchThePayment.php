<?php

namespace Goldnead\Invoices\Exceptions;

use Goldnead\StatamicPayments\Models\Payment;

/**
 * The document would not add up to the money that arrived.
 *
 * An invoice derived from a payment has exactly one external check available to
 * it: the total has to be the amount that was actually charged. Everything else
 * about a tax document is internally consistent by construction — the lines add
 * up because the same code added them up, and a wrong rate produces a wrong
 * invoice that looks exactly like a right one.
 *
 * This is the one place a mistake can be seen from outside, so it is checked.
 * It catches the whole family at once: a price basis set the wrong way (€22.61
 * against a payment of €19.00), a rate applied where none belongs, a discount
 * split that lost a cent, a line quantity that drifted.
 *
 * The payment stands. Only the document is refused, and `invoices:pending`
 * lists it with this reason.
 */
class DoesNotMatchThePayment extends InvoiceNotWritten
{
    public function __construct(
        public readonly Payment $payment,
        public readonly int $grossCent,
    ) {
        $bezahlt = number_format($payment->amount_cent / 100, 2, ',', '.');
        $rechnung = number_format($grossCent / 100, 2, ',', '.');

        parent::__construct(
            "Invoice for payment {$payment->getKey()} would total {$rechnung} "
            ."{$payment->currency} against a payment of {$bezahlt} {$payment->currency}. "
            .'No invoice was written: a document that does not add up to the money is wrong '
            .'even when every line in it is arithmetically correct. The usual cause is '
            .'invoices.tax.prices_include_tax pointing the wrong way — set it to true if your '
            .'catalogue prices are gross.'
        );
    }
}
