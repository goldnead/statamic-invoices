<?php

namespace Goldnead\Invoices\Support;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Models\Invoice;

/**
 * The bundled PDF engine: the same HTML, printed.
 *
 * **Why dompdf.** It is pure PHP. Every other candidate makes an infrastructure
 * decision on the host's behalf — Browsershot wants Node and a Chromium,
 * wkhtmltopdf wants a binary and a queue worker to keep it off the request —
 * and an addon that quietly adds a system dependency is an addon that stops
 * being installable. This one runs wherever Statamic runs.
 *
 * **It renders {@see Renderer}'s HTML, it does not have a template of its own.**
 * A second implementation of the document is a second thing to keep in step,
 * and the two would drift on the first change nobody made twice.
 *
 * **Reproducible, byte for byte.** dompdf otherwise stamps every file with the
 * wall clock (`CreationDate`, `ModDate`) and a random document id (`/ID`), so
 * two renders of the same invoice would differ. Both are overwritten here with
 * values derived from the invoice: the creation date is the date it was issued,
 * which is also the truer answer — the document came into being when the
 * invoice did, not when somebody downloaded it a second time. Without this the
 * promise "the same invoice is the same document" would hold for the visible
 * content and quietly fail for the file.
 */
class DompdfRenderer implements PdfRenderer
{
    public function __construct(protected Renderer $document) {}

    public function render(Invoice $invoice): string
    {
        $dompdf = new Dompdf($this->options());

        $dompdf->setPaper((string) config('invoices.pdf.paper', 'A4'), 'portrait');
        $dompdf->loadHtml($this->document->html($invoice), 'UTF-8');
        $dompdf->render();

        $this->makeReproducible($dompdf, $invoice);

        return (string) $dompdf->output();
    }

    protected function options(): Options
    {
        $options = new Options;

        // An invoice has to be readable in ten years. One that fetches a font
        // or a logo over the network is a blank page as soon as that host
        // moves, and by then nobody may amend it. The template ships without
        // remote assets for the same reason; this makes it enforceable rather
        // than a convention.
        $options->setIsRemoteEnabled(false);

        // Never execute anything the template happens to contain. A document
        // built from customer-supplied names has no business running code.
        $options->setIsPhpEnabled(false);

        // `print`, not `screen`. The template already distinguishes the two —
        // the on-screen preview carries its own padding, the printed page gets
        // its margin from `@page` — and rendering the screen variant would put
        // both on the same sheet.
        $options->setDefaultMediaType('print');

        return $options;
    }

    /**
     * Strip the two things dompdf writes that are about *now* instead of about
     * the invoice.
     *
     * Guarded on the adapter: a host may configure a different canvas backend,
     * and losing byte-equality is better than losing the PDF.
     */
    protected function makeReproducible(Dompdf $dompdf, Invoice $invoice): void
    {
        $canvas = $dompdf->getCanvas();

        if (! $canvas instanceof CPDF) {
            return;
        }

        $pdf = $canvas->get_cpdf();

        // UTC, so the same invoice rendered on two machines with different
        // `app.timezone` settings still produces the same bytes.
        $stamp = 'D:'.$invoice->issued_at->clone()->utc()->format('YmdHis')."+00'00'";

        $pdf->addInfo('CreationDate', $stamp);
        $pdf->addInfo('ModDate', $stamp);

        // The document identifier. dompdf derives it from `microtime()` and
        // `mt_rand()` when it is empty, which is exactly once per render.
        $pdf->fileIdentifier = md5('invoice:'.$invoice->getKey().':'.$invoice->number.':'.$stamp);
    }
}
