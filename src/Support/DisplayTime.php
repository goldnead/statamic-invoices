<?php

namespace Goldnead\Invoices\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The shop's calendar, for the dates an invoice states.
 *
 * The database keeps UTC. A purchase at 00:30 in Berlin is dated that day on
 * the invoice, although UTC still says the evening before. The zone is read in
 * this order, the first valid name wins:
 *
 * 1. `invoices.display_timezone` (settings screen, per brand),
 * 2. `statamic-payments.display_timezone` (the sibling's, so one setting suffices),
 * 3. `statamic.system.display_timezone`,
 * 4. `app.timezone`.
 *
 * **`app.timezone` is never turned to fix a date.** The time columns hold UTC
 * without a zone; another application zone rewrites the meaning of every
 * stored timestamp, silently.
 */
final class DisplayTime
{
    public static function zone(): string
    {
        foreach ([
            config('invoices.display_timezone'),
            config('statamic-payments.display_timezone'),
            config('statamic.system.display_timezone'),
            config('app.timezone'),
        ] as $candidate) {
            if (is_string($candidate) && ($candidate = trim($candidate)) !== '' && self::valid($candidate)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    /** A stored moment, in the shop's zone. */
    public static function of(CarbonInterface $moment): Carbon
    {
        return Carbon::instance($moment)->copy()->setTimezone(self::zone());
    }

    /** The shop's calendar day of a stored moment, as `Y-m-d`. */
    public static function day(CarbonInterface $moment): string
    {
        return self::of($moment)->toDateString();
    }

    /**
     * A rhythm in the provider's words ("1 month", "12 months", "1 year",
     * "2 weeks", "14 days") on a date. Months and years without overflow: 31
     * January plus a month is 28 February, not 3 March. The same rule as
     * `Subscription::addInterval()` in statamic-payments, repeated here because
     * the versions of payments this addon accepts do not all have it. Null for
     * words that are not a rhythm.
     */
    public static function addInterval(Carbon $from, string $interval): ?Carbon
    {
        if (! preg_match('/^(\d+)\s*(day|week|month|year)s?$/i', trim($interval), $m) || (int) $m[1] < 1) {
            return null;
        }

        $n = (int) $m[1];

        return match (strtolower($m[2])) {
            'day' => $from->copy()->addDays($n),
            'week' => $from->copy()->addWeeks($n),
            'month' => $from->copy()->addMonthsNoOverflow($n),
            default => $from->copy()->addYearsNoOverflow($n),
        };
    }

    private static function valid(string $zone): bool
    {
        try {
            new DateTimeZone($zone);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
