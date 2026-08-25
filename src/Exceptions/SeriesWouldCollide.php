<?php

namespace Goldnead\Invoices\Exceptions;

/**
 * Two brands would count in the same number series.
 *
 * The counter is per brand, which is what "one series per brand" means. The
 * number itself is globally unique, which is what makes it evidence. Put those
 * together with a shared prefix and the two brands hand out `RE2026-08-001`
 * twice — the first one wins and the second is an exception on a paid order.
 *
 * There is no safe guess here. Deriving a prefix from the brand handle would
 * silently change the numbering of an installation that adds a brand, and a
 * number series that changes shape is one nobody can explain to an auditor. So
 * this asks instead: give the brand its own prefix.
 */
class SeriesWouldCollide extends InvoiceNotWritten
{
    public function __construct(public readonly int $brandId)
    {
        parent::__construct(
            "Brand {$brandId} has no invoice prefix of its own, and this installation runs several "
            .'brands. Two brands sharing a prefix would hand out the same number twice. Set '
            ."invoices.number.prefix_per_brand.{$brandId} to something distinct."
        );
    }
}
