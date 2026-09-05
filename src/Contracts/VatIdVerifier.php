<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Contracts;

use Goldnead\Invoices\Support\VatIdCheck;

/**
 * Asks somebody official whether a VAT ID is real.
 *
 * An interface, because the answer comes over the network and the network is
 * the part a test must be able to replace — and because Germany has two
 * addresses for the same question. VIES is the EU-wide one; the BZSt
 * Bestätigungsabfrage (§ 18e UStG) is the German one, and only its answer is
 * the confirmation a German tax office accepts without argument. Shipping VIES
 * and leaving the seam is the honest split: one implementation that works
 * everywhere, and a named place for the one that works better here.
 *
 * The contract is narrow on purpose. An implementation **never throws** for a
 * network problem: an unreachable service is a `pending` check, not an
 * exception that takes a paid purchase down with it.
 */
interface VatIdVerifier
{
    /**
     * @param  string  $vatId  Normalised, i.e. upper case without spaces: "ATU12345678".
     * @param  string|null  $requesterVatId  The seller's own number. Present, this makes
     *                                       the enquiry a qualified one and the answer carries a reference that can
     *                                       be quoted later; absent, the answer is a bare yes or no.
     */
    public function verify(string $vatId, ?string $requesterVatId = null): VatIdCheck;

    /** What goes on the invoice as the source of the answer, e.g. "vies". */
    public function name(): string;
}
