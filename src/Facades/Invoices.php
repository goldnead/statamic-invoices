<?php

namespace Goldnead\Invoices\Facades;

use Goldnead\Invoices\InvoiceWriter;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\Invoices\Models\Invoice|null forPayment(\Goldnead\StatamicPayments\Models\Payment $payment)
 * @method static \Goldnead\Invoices\Models\Invoice|null creditNoteFor(\Goldnead\StatamicPayments\Models\Payment $payment)
 *
 * @see InvoiceWriter
 */
class Invoices extends Facade
{
    protected static function getFacadeAccessor()
    {
        return InvoiceWriter::class;
    }
}
