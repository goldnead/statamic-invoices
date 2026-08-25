<?php

namespace Goldnead\Invoices\Support;

use Goldnead\Invoices\Models\Invoice;
use Illuminate\Contracts\View\View;

/**
 * The invoice as a document.
 *
 * HTML, and only HTML. Turning it into a PDF is somebody else's job — a print
 * dialog, a headless browser, a queue worker with wkhtmltopdf — and every one
 * of those is a decision about infrastructure that an addon should not make for
 * its host. What this guarantees instead is that whatever renders it sees
 * exactly what the preview showed: one template, no second implementation that
 * can drift.
 */
class Renderer
{
    public function view(Invoice $invoice): View
    {
        return view('invoices::invoice', [
            'invoice' => $invoice->loadMissing('items'),
            'seller' => (array) ($invoice->seller ?? []),
            // Die Nachlass-Spalte erscheint nur, wenn es einen gibt: eine
            // Spalte voller Striche ist kein Beleg, sondern Rauschen.
            'hatRabatt' => $invoice->items->sum('discount_cent') > 0,
            // Currency formatting as a closure rather than a helper, so the
            // template cannot reach for a global that a host may have redefined.
            'euro' => fn (int $cent) => number_format($cent / 100, 2, ',', '.').' '.$this->symbol($invoice->currency),
        ]);
    }

    public function html(Invoice $invoice): string
    {
        return $this->view($invoice)->render();
    }

    protected function symbol(?string $currency): string
    {
        return match (strtoupper((string) $currency)) {
            'EUR' => '€',
            'CHF' => 'CHF',
            'GBP' => '£',
            'USD' => '$',
            default => (string) $currency,
        };
    }
}
