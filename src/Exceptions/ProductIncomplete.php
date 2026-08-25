<?php

namespace Goldnead\Invoices\Exceptions;

/**
 * A product in the catalogue is missing something an invoice needs.
 *
 * Today that is `digital`, which decides between four different mandatory
 * statements on the document: reverse charge, intra-community supply, outside
 * scope, export. A default of "digital" would print one of them on a record
 * shipped in a box, and the document cannot be corrected afterwards.
 */
class ProductIncomplete extends InvoiceNotWritten
{
    public function __construct(
        public readonly ?string $handle,
        public readonly string $missing,
    ) {
        parent::__construct(
            "The product '{$handle}' does not say whether it is '{$this->missing}'. "
            .'Add it to config/statamic-payments.php — this decides which mandatory tax note '
            .'goes on the invoice, and an invoice cannot be corrected afterwards.'
        );
    }
}
