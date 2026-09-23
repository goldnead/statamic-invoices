<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Jobs;

use Goldnead\Invoices\Export\ArchiveStore;
use Goldnead\Invoices\Export\PdfArchive;
use Goldnead\Invoices\Export\Period;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ZIP of a period's PDFs, built off the request.
 *
 * A quarter is a few hundred documents and dompdf renders one in a noticeable
 * fraction of a second; in the request that is a gateway timeout and a half
 * written file. Queued, the Control Panel says "being built" and lists the
 * file once it is there. On the `sync` driver it simply runs where it is
 * dispatched, which is what a small installation without a worker gets.
 *
 * Built into a temporary file and moved onto the disk in one step, so the
 * listing never offers half an archive. A marker next to it says it is being
 * built, and a failure replaces the marker with the reason.
 */
class BuildPdfArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** One try: a render that failed once fails the same way again, and the marker says why. */
    public int $tries = 1;

    /**
     * Seconds. The queue connection's `retry_after` has to be longer than this,
     * or a second worker picks the job up while the first is still rendering.
     */
    public const TIMEOUT = 1800;

    public int $timeout = self::TIMEOUT;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $brandId = null,
    ) {}

    public function handle(): void
    {
        $store = ArchiveStore::make();
        $period = Period::between($this->from, $this->to);
        $name = $store->nameFor($period, $this->brandId);
        $reserved = (string) tempnam(sys_get_temp_dir(), 'invoices-archive-');
        $temp = $reserved.'.zip';

        $store->markPending($name);

        try {
            app(PdfArchive::class)->build($period, $this->brandId, $temp);
            $store->put($name, $temp);
            $store->clearMarker($name);
        } catch (Throwable $e) {
            Log::error('statamic-invoices: the PDF archive could not be built.', [
                'period' => $period->slug(),
                'brand_id' => $this->brandId,
                'exception' => $e->getMessage(),
            ]);

            $store->markFailed($name, $e->getMessage());

            throw $e;
        } finally {
            @unlink($temp);
            @unlink($reserved);
        }
    }

    /**
     * Called by the queue when the job is given up on, including when the
     * worker killed it at `$timeout` and handle() never reached its catch.
     * Without it the screen would say "being built" until someone deleted a file.
     */
    public function failed(?Throwable $e = null): void
    {
        $store = ArchiveStore::make();

        $store->markFailed(
            $store->nameFor(Period::between($this->from, $this->to), $this->brandId),
            $e?->getMessage() ?: __('invoices::exports.archive_failed'),
        );
    }
}
