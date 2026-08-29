<?php

namespace Goldnead\Invoices\Sending;

use Goldnead\BrandContext\Sending\BrandMailer as BrandContextMailer;
use Goldnead\Invoices\Contracts\SenderIdentityResolver;

/**
 * The one door every mail in this package leaves through.
 *
 * The stakes are higher here than for a newsletter. An invoice names the seller
 * as a matter of law (§ 14 Abs. 4 Nr. 1 UStG), and the buyer keeps it for ten
 * years. If it arrives from another brand's address, the envelope contradicts
 * the document it carries — and the document is the one that cannot be
 * corrected afterwards.
 *
 * Which is why a brand that declares a mail identity and gets it wrong sends
 * nothing at all. The invoice still exists; only the delivery is refused, and
 * loudly. Falling back to the host-wide From would be the silent version of the
 * same failure.
 */
class BrandMailer extends BrandContextMailer
{
    public function __construct(SenderIdentityResolver $identities)
    {
        parent::__construct($identities);
    }
}
