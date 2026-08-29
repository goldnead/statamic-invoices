<?php

namespace Goldnead\Invoices\Support;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Models\Invoice;
use Illuminate\Contracts\View\View;

/**
 * The invoice as a document.
 *
 * HTML, and only HTML. What comes after — the print dialog, the PDF, whatever a
 * host does with it — reads *this* output, never a second template of its own.
 * That is the whole guarantee: the preview and the file the buyer keeps are the
 * same document, because there is only one.
 *
 * {@see PdfRenderer} is the seam where it becomes a file, and
 * {@see DompdfRenderer} the engine that ships with it.
 */
class Renderer
{
    public function view(Invoice $invoice): View
    {
        return view('invoices::invoice', [
            'invoice' => $invoice->loadMissing('items'),
            'seller' => (array) ($invoice->seller ?? []),
            // Zusammengesetzt hier statt in der Vorlage. Name und Anschrift des
            // Leistungsempfaengers sind Pflichtangabe (§ 14 Abs. 4 Nr. 1), und
            // welche Teile davon vorhanden sind, ist eine Frage der Daten, nicht
            // des Layouts.
            'empfaenger' => implode("\n", array_filter([
                $invoice->buyer_name,
                $invoice->buyer_address,
                $invoice->buyer_country,
            ])),
            // Die Nachlass-Spalte erscheint nur, wenn es einen gibt: eine
            // Spalte voller Striche ist kein Beleg, sondern Rauschen.
            'hatRabatt' => $invoice->items->sum('discount_cent') > 0,
            // Nach Steuersaetzen aufgeschluesselt, weil § 14 Abs. 4 Nr. 8 UStG
            // genau das verlangt. In der Reihenfolge, in der die Saetze auf der
            // Rechnung zuerst vorkommen — eine Umsortierung waere eine Aussage,
            // die niemand getroffen hat.
            'nachSatz' => $invoice->items
                ->groupBy('tax_rate_bp')
                ->map(fn ($zeilen) => [
                    'label' => $zeilen->first()->ratePercent().' %',
                    'net' => $zeilen->sum('net_cent'),
                    'tax' => $zeilen->sum('tax_cent'),
                ])
                ->values()
                ->all(),
            // Currency formatting as a closure rather than a global the template
            // could reach for and a host could have redefined. The formatting
            // itself lives in `Money`, because the mail that carries the
            // document names the same total in its first sentence.
            'euro' => fn (int $cent) => Money::format($cent, $invoice->currency),
        ]);
    }

    public function html(Invoice $invoice): string
    {
        return $this->view($invoice)->render();
    }
}
