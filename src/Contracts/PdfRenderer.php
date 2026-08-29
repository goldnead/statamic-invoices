<?php

namespace Goldnead\Invoices\Contracts;

use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\DompdfRenderer;

/**
 * Turns an invoice into a PDF.
 *
 * An interface rather than a class because the choice of engine is an
 * infrastructure decision and belongs to the host. The bundled implementation
 * is pure PHP and needs nothing installed; a host that already runs a headless
 * browser, or that has a print house with its own template, rebinds this:
 *
 *     $this->app->bind(PdfRenderer::class, MyRenderer::class);
 *
 * Two obligations for anything bound here, and both come from the fact that an
 * invoice is immutable:
 *
 * 1. **Read, never recalculate.** Everything the document says is already on
 *    the row and its items. An implementation that looks a rate or a price up
 *    again produces a second copy that disagrees with the first one the buyer
 *    already has.
 * 2. **The same invoice yields the same bytes.** No render timestamp, no
 *    random document id. That is what makes a re-download the same document
 *    rather than a similar one — see {@see DompdfRenderer}
 *    for how the bundled one gets there.
 */
interface PdfRenderer
{
    /**
     * The invoice as PDF bytes.
     */
    public function render(Invoice $invoice): string;
}
