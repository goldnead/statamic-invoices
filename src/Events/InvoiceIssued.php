<?php

namespace Goldnead\Invoices\Events;

use Goldnead\Invoices\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice exists.
 *
 * The seam for everything this addon deliberately does not do: sending it,
 * filing it, handing it to an accountant. Fired after the transaction that
 * wrote it, so a listener always finds the row.
 */
class InvoiceIssued
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
