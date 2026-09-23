<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Http\Controllers\Cp;

use Goldnead\Invoices\Cp\Exports;
use Goldnead\Invoices\Export\ArchiveStore;
use Goldnead\Invoices\Export\CsvExport;
use Goldnead\Invoices\Export\CsvFormat;
use Goldnead\Invoices\Export\Period;
use Goldnead\Invoices\Export\TaxReport;
use Goldnead\Invoices\Jobs\BuildPdfArchive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The downloads behind the "Invoice export" utility.
 *
 * Registered through `Utility::register()->routes()`, so every route here sits
 * behind `can:access invoice-exports utility` and nothing in this class has to
 * remember to ask. The brand is the one the Control Panel is looking at, never
 * one from the request: a query parameter would be a way to read another
 * brand's documents.
 *
 * The CSVs are streamed. A year of documents never exists as one string in
 * this process, and the first bytes reach the browser while the rest is still
 * being read, so a large period does not run into the request timeout.
 */
class ExportController extends Controller
{
    public function csv(Request $request): Response
    {
        [$period, $format] = $this->read($request);

        if ($period === null) {
            return $this->backWithError($request);
        }

        $brandId = Exports::currentBrandId();

        return $this->stream(
            fn ($out) => (new CsvExport($format))->write($out, $period, $brandId),
            'Belege_'.$period->slug().'.csv',
            $format,
        );
    }

    public function report(Request $request): Response
    {
        [$period, $format] = $this->read($request);

        if ($period === null) {
            return $this->backWithError($request);
        }

        $brandId = Exports::currentBrandId();

        return $this->stream(
            fn ($out) => TaxReport::for($period, $brandId)->writeCsv($out, $format),
            'Steuerbericht_'.$period->slug().'.csv',
            $format,
        );
    }

    public function archive(Request $request): RedirectResponse
    {
        try {
            $period = Period::fromOptions($request->all()) ?? Period::lastMonth();
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $brandId = Exports::currentBrandId();
        $store = ArchiveStore::make();

        // Marked here as well as in the job, so the screen the redirect lands on
        // already shows it. With a worker behind the queue the job may not have
        // started by then, and an empty list reads as "nothing happened".
        $store->markPending($store->nameFor($period, $brandId));

        BuildPdfArchive::dispatch($period->from->toDateString(), $period->to->toDateString(), $brandId);

        return redirect(cp_route('utilities.invoice-exports', $period->toQuery()))
            ->with('success', __('invoices::exports.archive_queued', ['period' => $period->label()]));
    }

    public function download(string $file): Response
    {
        $store = ArchiveStore::make();

        abort_unless($store->exists($file), 404);

        return $store->download($file);
    }

    /**
     * @return array{0: Period|null, 1: CsvFormat}
     */
    private function read(Request $request): array
    {
        try {
            return [
                Period::fromOptions($request->query()) ?? Period::lastMonth(),
                CsvFormat::fromOptions($request->query()),
            ];
        } catch (InvalidArgumentException $e) {
            $request->attributes->set('invoices.export_error', $e->getMessage());

            return [null, CsvFormat::default()];
        }
    }

    private function backWithError(Request $request): RedirectResponse
    {
        return redirect(cp_route('utilities.invoice-exports'))
            ->with('error', (string) $request->attributes->get('invoices.export_error'));
    }

    /**
     * @param  callable(resource): mixed  $write
     */
    private function stream(callable $write, string $filename, CsvFormat $format): StreamedResponse
    {
        $charset = $format->encoding === 'windows-1252' ? 'windows-1252' : 'utf-8';

        return response()->streamDownload(function () use ($write) {
            $out = fopen('php://output', 'wb');
            $write($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset='.$charset,
            'Cache-Control' => 'no-store',
        ]);
    }
}
