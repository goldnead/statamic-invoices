<?php

namespace Goldnead\Invoices\Events;

use Goldnead\Invoices\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The invoice reached the buyer's mailbox.
 *
 * An event rather than a column, because the row cannot take one: an invoice
 * refuses every update once it exists, and rightly so — a document whose fields
 * can still move is not evidence. So the fact that it went out is recorded
 * beside it, by whoever needs to record it, and this is the seam.
 *
 * Carries the address it actually left for. That is `buyer_email` today, but it
 * is the answer to "where did this go", and reading it back off the invoice
 * later would answer a different question.
 */
class InvoiceDelivered
{
    use Dispatchable;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $to,
    ) {}
}
