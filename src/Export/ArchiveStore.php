<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

use Goldnead\Invoices\Jobs\BuildPdfArchive;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where finished archives wait to be downloaded, and what is still being built.
 *
 * A disk and a directory from `invoices.export`, `local` and
 * `invoices/exports` unless configured. Not a public disk: these are every
 * invoice of a period with names and addresses on them, and they leave only
 * through the Control Panel route that checks the utility permission.
 *
 * Next to each archive name there can be a marker: `.pending` while the job
 * runs, `.failed` with the reason when it did not finish. A listing that only
 * showed finished files would leave someone waiting for a file that is never
 * coming.
 */
final class ArchiveStore
{
    /** What an archive name may look like. Anything else is not ours to hand out. */
    private const NAME = '/^[A-Za-z0-9._-]+\.zip$/';

    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $directory,
    ) {}

    public static function make(): self
    {
        $disk = config('invoices.export.disk', 'local');
        $directory = config('invoices.export.directory', 'invoices/exports');

        return new self(
            Storage::disk(is_string($disk) && $disk !== '' ? $disk : 'local'),
            trim(is_string($directory) && $directory !== '' ? $directory : 'invoices/exports', '/'),
        );
    }

    public function nameFor(Period $period, ?int $brandId): string
    {
        return 'Belege_'.$period->slug().self::brandSuffix($brandId).'.zip';
    }

    /** `_marke-2` for a brand, nothing for an installation without brands. Also used for the CSV names. */
    public static function brandSuffix(?int $brandId): string
    {
        return $brandId !== null ? '_marke-'.$brandId : '';
    }

    /**
     * Does this archive belong to the brand the Control Panel is looking at?
     *
     * Read off the name, which the job wrote from the same brand id. A user
     * switched to one brand must not list, let alone download, the invoices of
     * another, even when both brands share one disk.
     */
    public function belongsTo(string $name, ?int $brandId): bool
    {
        $owner = preg_match('/_marke-(\d+)\.zip$/', $name, $m) === 1 ? (int) $m[1] : null;

        return $owner === $brandId;
    }

    public function isValidName(string $name): bool
    {
        return preg_match(self::NAME, $name) === 1 && ! str_contains($name, '..');
    }

    public function markPending(string $name): void
    {
        $this->disk->delete($this->path($name).'.failed');
        $this->disk->put($this->path($name).'.pending', now()->toIso8601String());
    }

    public function markFailed(string $name, string $reason): void
    {
        $this->disk->delete($this->path($name).'.pending');
        $this->disk->put($this->path($name).'.failed', $reason);
    }

    public function clearMarker(string $name): void
    {
        $this->disk->delete([$this->path($name).'.pending', $this->path($name).'.failed']);
    }

    public function put(string $name, string $localFile): void
    {
        $stream = fopen($localFile, 'rb');

        if ($stream === false) {
            throw new RuntimeException(sprintf('The archive %s could not be read back.', $localFile));
        }

        try {
            if (! $this->disk->writeStream($this->path($name), $stream)) {
                throw new RuntimeException(sprintf('The archive could not be written to %s.', $this->path($name)));
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function exists(string $name): bool
    {
        return $this->isValidName($name) && $this->disk->exists($this->path($name));
    }

    public function download(string $name): StreamedResponse
    {
        return $this->disk->download($this->path($name), $name, ['Content-Type' => 'application/zip']);
    }

    /**
     * The archives of one brand, newest first.
     *
     * A `.pending` marker older than the job may run ({@see BuildPdfArchive::$timeout})
     * is listed as failed. A worker killed at its timeout never reaches the job's
     * own error handling, and "being built" would otherwise stand there forever.
     *
     * @return array{ready: list<array{name: string, size: int, modified: int}>, pending: list<string>, failed: list<array{name: string, reason: string}>}
     */
    public function listing(?int $brandId): array
    {
        $ready = [];
        $pending = [];
        $failed = [];
        $limit = BuildPdfArchive::TIMEOUT;

        foreach ($this->disk->files($this->directory) as $file) {
            $base = basename($file);
            $archive = (string) preg_replace('/\.(pending|failed)$/', '', $base);

            if (! $this->isValidName($archive) || ! $this->belongsTo($archive, $brandId)) {
                continue;
            }

            if (str_ends_with($base, '.zip.pending')) {
                $since = strtotime(trim((string) $this->disk->get($file))) ?: (int) $this->disk->lastModified($file);

                if (time() - $since > $limit) {
                    $failed[] = ['name' => $archive, 'reason' => __('invoices::exports.archive_stale', ['minutes' => intdiv($limit, 60)])];
                } else {
                    $pending[] = $archive;
                }
            } elseif (str_ends_with($base, '.zip.failed')) {
                $failed[] = ['name' => $archive, 'reason' => (string) $this->disk->get($file)];
            } else {
                $ready[] = ['name' => $base, 'size' => (int) $this->disk->size($file), 'modified' => (int) $this->disk->lastModified($file)];
            }
        }

        usort($ready, fn (array $a, array $b) => $b['modified'] <=> $a['modified']);

        return ['ready' => $ready, 'pending' => $pending, 'failed' => $failed];
    }

    private function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }
}
