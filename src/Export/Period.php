<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Export;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A stretch of calendar days, first and last day included.
 *
 * Days in the application's own time zone, because that is the zone the
 * invoice date is written and printed in. An invoice dated 31.08. at 23:50 is
 * an August invoice on paper, and a period that cut at midnight UTC would move
 * it into September for a seller in Berlin.
 *
 * Refuses what it cannot read rather than guessing. "August" as a month, a
 * 13th month, an end before the start: each comes back as an exception with a
 * sentence, never as the current month, because an export of the wrong month
 * looks exactly like an export of the right one.
 */
final class Period
{
    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {
        if ($to->lessThan($from)) {
            throw new InvalidArgumentException(__('invoices::exports.error_order', [
                'to' => $to->format('d.m.Y'),
                'from' => $from->format('d.m.Y'),
            ]));
        }
    }

    public static function between(string $from, string $to): self
    {
        return new self(
            self::day($from)->startOfDay(),
            self::day($to)->endOfDay(),
        );
    }

    /** `2026-08` */
    public static function month(string $month): self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', trim($month), $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new InvalidArgumentException(__('invoices::exports.error_month', ['value' => $month]));
        }

        $start = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, self::zone());

        return new self($start, $start->endOfMonth());
    }

    /** `2026-Q3` or `2026-3` */
    public static function quarter(string $quarter): self
    {
        if (preg_match('/^(\d{4})-Q?([1-4])$/i', trim($quarter), $m) !== 1) {
            throw new InvalidArgumentException(__('invoices::exports.error_quarter', ['value' => $quarter]));
        }

        $start = CarbonImmutable::create((int) $m[1], ((int) $m[2] - 1) * 3 + 1, 1, 0, 0, 0, self::zone());

        return new self($start, $start->addMonths(2)->endOfMonth());
    }

    /** `2026` */
    public static function year(string $year): self
    {
        if (preg_match('/^\d{4}$/', trim($year)) !== 1) {
            throw new InvalidArgumentException(__('invoices::exports.error_year', ['value' => $year]));
        }

        $start = CarbonImmutable::create((int) $year, 1, 1, 0, 0, 0, self::zone());

        return new self($start, $start->endOfYear());
    }

    /**
     * The period from the options a command or a request carries, in this
     * order: `from`/`to`, `month`, `quarter`, `year`. Null when none is given,
     * so the caller decides the default and says which one it took.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromOptions(array $options): ?self
    {
        $wert = fn (string $key): ?string => is_string($options[$key] ?? null) && trim($options[$key]) !== ''
            ? trim($options[$key])
            : null;

        if ($wert('from') !== null || $wert('to') !== null) {
            if ($wert('from') === null || $wert('to') === null) {
                throw new InvalidArgumentException(__('invoices::exports.error_incomplete'));
            }

            return self::between((string) $wert('from'), (string) $wert('to'));
        }

        return match (true) {
            $wert('month') !== null => self::month((string) $wert('month')),
            $wert('quarter') !== null => self::quarter((string) $wert('quarter')),
            $wert('year') !== null => self::year((string) $wert('year')),
            default => null,
        };
    }

    /** The calendar month before the current one: what a monthly filing asks for. */
    public static function lastMonth(): self
    {
        return self::month(CarbonImmutable::now(self::zone())->startOfMonth()->subMonth()->format('Y-m'));
    }

    /** `01.08.2026 bis 31.08.2026` */
    public function label(): string
    {
        return __('invoices::exports.period_label', [
            'from' => $this->from->format('d.m.Y'),
            'to' => $this->to->format('d.m.Y'),
        ]);
    }

    /** `2026-08-01_2026-08-31`, for file names. */
    public function slug(): string
    {
        return $this->from->toDateString().'_'.$this->to->toDateString();
    }

    /** @return array{from: string, to: string} */
    public function toQuery(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    private static function day(string $value): CarbonImmutable
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $tag = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::zone());
        } elseif (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $value) === 1) {
            $tag = CarbonImmutable::createFromFormat('!d.m.Y', $value, self::zone());
        } else {
            $tag = false;
        }

        // createFromFormat rolls 2026-02-30 over into March. A date that is
        // not on the calendar is a typo, and a typo is not a period.
        if (! $tag instanceof CarbonImmutable
            || ($tag->format('Y-m-d') !== $value && $tag->format('d.m.Y') !== $value)) {
            throw new InvalidArgumentException(__('invoices::exports.error_date', ['value' => $value]));
        }

        return $tag;
    }

    private static function zone(): string
    {
        $zone = function_exists('config') ? config('app.timezone') : null;

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }
}
