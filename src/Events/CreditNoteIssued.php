<?php

namespace Goldnead\Invoices\Events;

use Goldnead\Invoices\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice was reversed by a second document.
 *
 * Carries both, because a credit note read on its own says nothing about what
 * it undid.
 */
class CreditNoteIssued
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $creditNote,
        public readonly Invoice $reverses,
    ) {}
}
