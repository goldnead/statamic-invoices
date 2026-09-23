<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Jobs\BuildPdfArchive;
use Goldnead\Invoices\Models\Invoice;
use RuntimeException;
use ZipArchive;

/**
 * Every document of a period as a PDF, in one ZIP.
 *
 * A ZIP rather than one merged PDF. A tax office or an adviser asks for "the
 * invoices of the third quarter" and files them one by one; a merged file has
 * to be cut apart again, and merging needs a second PDF library this addon
 * would carry for that alone. The name of each file is the invoice number,
 * which is also the order a person expects.
 *
 * Rendered through {@see PdfRenderer}, the same binding the download and the
 * mail use, so the file in the archive is byte for byte the file the buyer
 * received.
 *
 * Slow by nature: dompdf takes a noticeable fraction of a second per
 * document. That is why the Control Panel queues this
 * ({@see BuildPdfArchive}) instead of running it in
 * the request.
 */
final class PdfArchive
{
    /**
     * The archive closes and reopens every this many files. ZipArchive keeps
     * every added string in memory until close(); a year of documents would
     * otherwise sit in memory as one block.
     */
    private const FLUSH_EVERY = 100;

    public function __construct(private readonly PdfRenderer $renderer) {}

    /**
     * @return int the number of documents written
     */
    public function build(Period $period, ?int $brandId, string $path): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is missing, so no archive can be written. Install ext-zip.');
        }

        $zip = new ZipArchive;
        $this->open($zip, $path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $count = 0;
        $names = [];

        foreach (Documents::lazy($period, $brandId) as $invoice) {
            $zip->addFromString($this->nameFor($invoice, $names), $this->renderer->render($invoice));
            $count++;

            if ($count % self::FLUSH_EVERY === 0) {
                $zip->close();
                $this->open($zip, $path, ZipArchive::CREATE);
            }
        }

        // An empty ZIP is not written by ZipArchive at all. An empty period is
        // still an answer, so it gets a file that says so.
        if ($count === 0) {
            $zip->addFromString('LEER.txt', __('invoices::exports.archive_empty_file', ['period' => $period->label()]));
        }

        $zip->close();

        return $count;
    }

    /**
     * The invoice number as a file name, and never twice the same one.
     *
     * @param  array<string, true>  $names
     */
    private function nameFor(Invoice $invoice, array &$names): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $invoice->number) ?: 'beleg-'.$invoice->getKey();
        $name = $base.'.pdf';

        for ($i = 2; isset($names[$name]); $i++) {
            $name = $base.'-'.$i.'.pdf';
        }

        $names[$name] = true;

        return $name;
    }

    private function open(ZipArchive $zip, string $path, int $flags): void
    {
        $result = $zip->open($path, $flags);

        if ($result !== true) {
            throw new RuntimeException(sprintf('The archive %s could not be opened (ZipArchive error %s).', $path, (string) $result));
        }
    }
}
