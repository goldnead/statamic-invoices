<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Cp;

use Goldnead\Invoices\Models\Invoice;
use Illuminate\Http\Request;

/**
 * The data behind the "VAT ID: confirmation outstanding" utility.
 *
 * The other half of the fallback. Letting a sale through while the confirmation
 * service is down is only defensible if somebody sees the list afterwards —
 * otherwise "verification pending" is a phrase on a document and a decision
 * nobody ever makes.
 *
 * A class of its own rather than a controller, because a Statamic utility is
 * given a view and a callback that produces its data; there is no route of ours
 * for a controller to hang off. Which also makes the query testable without a
 * Control Panel around it.
 *
 * It shows, and it does not act. What to do about a number that turns out to be
 * invalid a week later is a judgement — dun the buyer, issue a credit note and a
 * corrected invoice, or leave it, because § 6a Abs. 4 UStG protects a seller who
 * relied in good faith on what the service said at the time. None of those is a
 * thing to automate behind somebody's back.
 */
final class OutstandingVatChecks
{
    /** @return array<string, mixed> */
    public function __invoke(?Request $request = null): array
    {
        $invoices = Invoice::query()
            ->awaitingVatIdConfirmation()
            ->with('vatIdChecks')
            ->orderByDesc('issued_at')
            ->paginate(50);

        return [
            'invoices' => $invoices,
            // Counted over the whole set, not over the page. "3 outstanding" that
            // silently means "3 on this page" is the kind of number somebody acts on.
            'total' => $invoices->total(),
        ];
    }
}
