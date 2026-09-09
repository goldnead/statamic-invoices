<?php

namespace Goldnead\Invoices\Exceptions;

/**
 * The next number of a series is already on a document.
 *
 * The counter runs per brand and per period; the number is unique across the
 * whole table. Both are wanted — one series per brand is the promise, a globally
 * unique number is what makes it evidence — and a prefix that *changes* after
 * invoices exist puts them against each other: the fresh counter starts at one
 * while `NL2026-09-001` is already issued and cannot be issued twice.
 *
 * There is no safe guess here either. Skipping ahead to the next free number
 * would leave a gap in a German series, and continuing somebody else's count
 * would fork it. So this asks, like {@see SeriesWouldCollide}: give the series
 * its own prefix, or set its counter past what is already out there.
 *
 * It inherits from {@see InvoiceNotWritten} because that is what it is — a
 * reason a person has to decide, not a reason to retry. Before it did, this
 * arrived as a `UniqueConstraintViolationException`, escaped the `PaymentPaid`
 * listener, and rolled back a fulfilment the buyer had already been given.
 *
 * One assumption, written down because it is invisible at the call site: the
 * writer decides this by elimination, not by asking which index was violated.
 * Exactly two unique indexes are reachable while an invoice row is written —
 * `number` and `(payment_id, kind)` — and the second is answered by returning
 * the invoice that already exists. Add a third to the table and this diagnosis
 * becomes a guess; then read the constraint off the driver instead. The
 * original database error rides along as `previous` and is logged with it, so
 * a wrong diagnosis stays checkable.
 */
class NumberAlreadyTaken extends InvoiceNotWritten
{
    public function __construct(
        public readonly int $brandId,
        public readonly string $series,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "The next number of series {$series} is already issued, so brand {$brandId} cannot count on. "
            .'That happens when a prefix changes after invoices exist. Either give this series a prefix of '
            ."its own, or set the counter for brand {$brandId} and series {$series} past the highest number "
            .'already out there.',
            0,
            $previous,
        );
    }
}
