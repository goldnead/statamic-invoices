<?php

namespace Goldnead\Invoices\Events;

use Goldnead\Invoices\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice exists.
 *
 * The seam for everything this addon deliberately does not do: sending it,
 * filing it, handing it to an accountant. Fired after the transaction that
 * wrote it, so a listener always finds the row. A host that calls the writer
 * inside its own transaction gets the event after *that* commit and never after
 * a rollback (`ShouldDispatchAfterCommit`): nobody learns an invoice number
 * that no row carries.
 */
class InvoiceIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
