<?php

/**
 * Stand-ins for the analytics addon's contract, under its own namespace.
 *
 * **Why a required file and not an autoload path.** A `psr-4` entry under
 * `autoload-dev` was the first attempt and was taken back out: it would map
 * `Goldnead\StatamicInsights\` to this directory for the whole dev install, so
 * the day the real package lands in `require-dev` — for a cross-addon test, say
 * — Composer would have two sources for one namespace and quietly serve
 * whichever it found first. A stand-in that shadows the thing it stands in for
 * is worse than no stand-in at all. Loaded by hand it can only ever be in play
 * where a test asked for it, which is the same trade `insights-facade.php`
 * makes one door down, and for the same reason.
 *
 * Each declaration is guarded, so this file is inert once the real package is
 * present: the tests then run against the genuine contract rather than against
 * a copy of it that has drifted.
 *
 * The signatures are copied byte for byte from
 * `statamic-insights/src/{Contracts,Support}`. That is the whole value of the
 * file — a stand-in with a looser signature would let a metric compile here and
 * fatal on the first install that has both addons.
 *
 * The base class the metrics here actually extend is **not** in this file:
 * `insights-table-metric.php` beside it is a verbatim copy of
 * `Support/TableMetric.php`, because that one is not a shape to match but real
 * query-building code the tests run through.
 *
 * **`InsightsContractsMatchTest` is what holds that claim to account.** Since
 * the sibling is only a `suggest`, the locks below never engage in this suite
 * and this copy is what every test and PHPStan actually run against; a method
 * added upstream would otherwise leave the suite green and break production.
 * That test finds the real package — in `vendor/` or checked out beside this
 * one — and compares the two shapes by reflection. If you edit this file, run
 * it.
 */

namespace Goldnead\StatamicInsights\Contracts {
    use Goldnead\StatamicInsights\Support\MetricQuery;

    if (! interface_exists('Goldnead\StatamicInsights\Contracts\Metric')) {
        interface Metric
        {
            public function handle(): string;

            public function label(): string;

            public function description(): ?string;

            public function group(): string;

            public function unit(): string;

            public function available(): bool;

            public function value(MetricQuery $query): int|float|null;

            /** @return array<string, int|float> */
            public function series(MetricQuery $query): array;

            /** @return array<string, mixed> */
            public function meta(MetricQuery $query): array;
        }
    }

    if (! interface_exists('Goldnead\StatamicInsights\Contracts\HasBreakdowns')) {
        interface HasBreakdowns
        {
            /** @return array<string, string> */
            public function breakdowns(): array;

            /** @return array<int, array{key: string|null, label: string, value: int|float, meta?: array<string, mixed>}> */
            public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array;
        }
    }

    if (! interface_exists('Goldnead\StatamicInsights\Contracts\HasFilterOptions')) {
        interface HasFilterOptions
        {
            /** @return array<string, array<int, array{value: string, label: string}>> */
            public function filterOptions(): array;
        }
    }
}

namespace Goldnead\StatamicInsights\Support {
    use Illuminate\Support\Carbon;

    if (! class_exists('Goldnead\StatamicInsights\Support\Unit')) {
        final class Unit
        {
            public const COUNT = 'count';

            public const CURRENCY = 'currency';

            public const PERCENT = 'percent';

            public const DURATION = 'duration';

            /** @return array<int, string> */
            public static function all(): array
            {
                return [self::COUNT, self::CURRENCY, self::PERCENT, self::DURATION];
            }
        }
    }

    if (! class_exists('Goldnead\StatamicInsights\Support\Period')) {
        final class Period
        {
            public const PRESETS = ['7d', '30d', '90d', '12m', 'ytd', 'all'];

            private function __construct(
                public readonly ?Carbon $from,
                public readonly ?Carbon $to,
                public readonly string $preset,
            ) {}

            /** The first moment after the period, for a half-open comparison. */
            public function toExclusive(): ?Carbon
            {
                return $this->to?->copy()->addSecond()->startOfSecond();
            }


            public static function fromPreset(?string $preset): self
            {
                $preset = in_array($preset, self::PRESETS, true) ? $preset : '30d';
                $now = Carbon::now();

                return match ($preset) {
                    '7d' => new self($now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay(), $preset),
                    '90d' => new self($now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay(), $preset),
                    '12m' => new self($now->copy()->subMonths(11)->startOfMonth(), $now->copy()->endOfDay(), $preset),
                    'ytd' => new self($now->copy()->startOfYear(), $now->copy()->endOfDay(), $preset),
                    'all' => new self(null, null, $preset),
                    default => new self($now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay(), '30d'),
                };
            }

            public static function between(Carbon $from, Carbon $to): self
            {
                return new self($from, $to, 'all');
            }

            public function previous(): ?self
            {
                if ($this->from === null || $this->to === null) {
                    return null;
                }

                $days = $this->days();
                $until = $this->from->copy()->subDay()->endOfDay();

                return new self(
                    $until->copy()->subDays($days - 1)->startOfDay(),
                    $until,
                    $this->preset,
                );
            }

            public function days(): ?int
            {
                if ($this->from === null || $this->to === null) {
                    return null;
                }

                return (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
            }

            public function isOpenEnded(): bool
            {
                return $this->from === null;
            }
        }
    }

    if (! class_exists('Goldnead\StatamicInsights\Support\MetricQuery')) {
        final class MetricQuery
        {
            public const BUCKET_DAY = 'day';

            public const BUCKET_MONTH = 'month';

            /** @param  array<string, mixed>  $filters */
            public function __construct(
                public readonly Period $period,
                public readonly string $bucket = self::BUCKET_DAY,
                public readonly array $filters = [],
            ) {}

            public function previous(): ?self
            {
                $before = $this->period->previous();

                return $before === null ? null : new self($before, $this->bucket, $this->filters);
            }

            public function with(string $key, mixed $value): self
            {
                return new self($this->period, $this->bucket, array_merge($this->filters, [$key => $value]));
            }

            public function filter(string $key, mixed $default = null): mixed
            {
                return $this->filters[$key] ?? $default;
            }

            public static function bucketFor(Period $period): string
            {
                return ($period->days() ?? 400) > 92 ? self::BUCKET_MONTH : self::BUCKET_DAY;
            }
        }
    }
}
