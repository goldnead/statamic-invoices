<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Cp;

use Carbon\CarbonImmutable;
use Goldnead\Invoices\Export\ArchiveStore;
use Goldnead\Invoices\Export\CsvFormat;
use Goldnead\Invoices\Export\Period;
use Goldnead\Invoices\Export\TaxReport;
use Goldnead\Invoices\Support\DisplayTime;
use Goldnead\Invoices\Support\Money;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * The data behind the "Invoice export" utility.
 *
 * A class of its own for the same reason as {@see OutstandingVatChecks}: a
 * utility is handed a view and a callback, and the callback is what can be
 * tested without a Control Panel around it.
 *
 * The period comes from the query string. One that cannot be read is not
 * replaced in silence: the screen falls back to the previous month *and says
 * why*, because a report of the wrong month looks exactly like the right one.
 */
final class Exports
{
    /**
     * The three CSV profiles the screen offers. The command line takes any
     * combination {@see CsvFormat} allows; a screen that asked for three
     * separate choices before every download would be asking the same three
     * questions every month.
     */
    public const PROFILES = [
        'excel' => ['delimiter' => ';', 'encoding' => 'utf-8-bom', 'decimal' => ','],
        'ansi' => ['delimiter' => ';', 'encoding' => 'windows-1252', 'decimal' => ','],
        'intl' => ['delimiter' => ',', 'encoding' => 'utf-8', 'decimal' => '.'],
    ];

    /** @return array<string, mixed> */
    public function __invoke(?Request $request = null): array
    {
        $request ??= request();
        $error = null;

        try {
            $period = Period::fromOptions($request->query()) ?? Period::lastMonth();
        } catch (InvalidArgumentException $e) {
            $error = __('invoices::exports.period_invalid', ['reason' => $e->getMessage()]);
            $period = Period::lastMonth();
        }

        $brandId = self::currentBrandId();
        $report = TaxReport::for($period, $brandId);
        $query = $period->toQuery();

        return [
            'period' => $period,
            'error' => $error,
            'report' => $report,
            'brandName' => $brandId !== null ? self::brandName($brandId) : null,
            'presets' => $this->presets(),
            'pageUrl' => cp_route('utilities.invoice-exports'),
            'csvLinks' => array_map(
                fn (array $format) => cp_route('utilities.invoice-exports.csv', [...$query, ...$format]),
                self::PROFILES,
            ),
            'reportCsvUrl' => cp_route('utilities.invoice-exports.report', $query),
            'archiveUrl' => cp_route('utilities.invoice-exports.archive'),
            'archives' => ArchiveStore::make()->listing($brandId),
            // The time a person reads, in the zone the Control Panel shows
            // every other date in, not the server's.
            'archivedAt' => fn (int $timestamp) => CarbonImmutable::createFromTimestamp(
                $timestamp,
                DisplayTime::zone(),
            )->format('d.m.Y H:i'),
            'downloadUrl' => fn (string $name) => cp_route('utilities.invoice-exports.download', ['file' => $name]),
            'euro' => fn (int $cent) => Money::format($cent, (string) (array_key_first($report->currencies) ?? 'EUR')),
            'percent' => fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',').' %',
            'size' => fn (int $bytes) => $bytes >= 1048576
                ? number_format($bytes / 1048576, 1, ',', '.').' MB'
                : number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.').' KB',
        ];
    }

    /**
     * The brand this screen is about, when the installation runs several.
     *
     * Null in single-brand mode and without brand-context: then every
     * document of the installation is the answer, which is also what the
     * series looks like there.
     */
    public static function currentBrandId(): ?int
    {
        if (! class_exists('\Goldnead\BrandContext\Facades\BrandContext')) {
            return null;
        }

        try {
            $manager = app('brand-context');

            return $manager->multiBrandEnabled() ? (int) $manager->currentId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function brandName(int $brandId): string
    {
        try {
            $brand = app('brand-context')->current();

            return (int) $brand->id === $brandId && is_string($brand->name ?? null) ? $brand->name : '#'.$brandId;
        } catch (Throwable) {
            return '#'.$brandId;
        }
    }

    /** @return list<array{label: string, url: string}> */
    private function presets(): array
    {
        $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $quarter = fn (CarbonImmutable $d) => $d->format('Y').'-Q'.$d->quarter;

        $options = [
            'preset_last_month' => ['month' => $now->startOfMonth()->subMonth()->format('Y-m')],
            'preset_this_month' => ['month' => $now->format('Y-m')],
            'preset_last_quarter' => ['quarter' => $quarter($now->startOfQuarter()->subMonth())],
            'preset_this_quarter' => ['quarter' => $quarter($now)],
            'preset_last_year' => ['year' => (string) ($now->year - 1)],
            'preset_this_year' => ['year' => (string) $now->year],
        ];

        $presets = [];

        foreach ($options as $key => $query) {
            $presets[] = [
                'label' => __('invoices::exports.'.$key),
                'url' => cp_route('utilities.invoice-exports', $query),
            ];
        }

        return $presets;
    }
}
