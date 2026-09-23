<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

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
        return 'Belege_'.$period->slug().($brandId !== null ? '_marke-'.$brandId : '').'.zip';
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
     * Newest first.
     *
     * @return array{ready: list<array{name: string, size: int, modified: int}>, pending: list<string>, failed: list<array{name: string, reason: string}>}
     */
    public function listing(): array
    {
        $ready = [];
        $pending = [];
        $failed = [];

        foreach ($this->disk->files($this->directory) as $file) {
            $base = basename($file);

            if (str_ends_with($base, '.zip.pending')) {
                $pending[] = substr($base, 0, -strlen('.pending'));
            } elseif (str_ends_with($base, '.zip.failed')) {
                $failed[] = ['name' => substr($base, 0, -strlen('.failed')), 'reason' => (string) $this->disk->get($file)];
            } elseif ($this->isValidName($base)) {
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
