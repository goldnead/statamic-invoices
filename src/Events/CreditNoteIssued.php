<?php

namespace Goldnead\Invoices\Events;

use Goldnead\Invoices\Models\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice was reversed by a second document.
 *
 * Carries both, because a credit note read on its own says nothing about what
 * it undid.
 *
 * Like `InvoiceIssued`, held back until a host's outer transaction commits and never
 * fired after its rollback.
 */
class CreditNoteIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $creditNote,
        public readonly Invoice $reverses,
    ) {}
}
