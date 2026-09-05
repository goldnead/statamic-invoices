<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Http\Controllers;

use Goldnead\Invoices\Http\Middleware\RequireBusinessBuyer;
use Goldnead\Invoices\Support\BuyerAdmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Will this buyer get through?", asked before the buyer has filled in a card.
 *
 * A checkout that discovers a missing VAT ID after the payment has started has
 * discovered it too late. So the form asks here while the buyer is typing, and
 * the answer is the same one {@see RequireBusinessBuyer}
 * will give — same class, same rules, so the field cannot go green and the
 * submit still fail.
 *
 * **It is not the gate.** This is an answer to a question, and a client is free
 * to ignore it. The gate is the middleware on the checkout route, which asks
 * again on the server. Anything else would be a check a curl request walks past.
 */
class BuyerCheckController
{
    public function __construct(private readonly BuyerAdmission $admission) {}

    public function __invoke(Request $request): JsonResponse
    {
        $verdict = $this->admission->for(
            country: $this->input($request, 'country'),
            vatId: $this->input($request, 'vat_id'),
            company: $this->input($request, 'company'),
            businessConfirmed: $request->boolean('business_confirmed'),
        );

        if ($verdict->refused()) {
            return response()->json([
                'admitted' => false,
                'code' => $verdict->code,
                'message' => $verdict->message,
            ], 422);
        }

        return response()->json([
            'admitted' => true,
            'zone' => $verdict->zone?->value,
            // The verdict and its date, so the form can say "confirmed" or
            // "confirmation pending" while the buyer is still on the page. Not the
            // reference: that is evidence for the seller and belongs on the
            // document, not in a response anybody can ask for.
            'vat_id_status' => $verdict->check?->status->value,
            'vat_id_checked_at' => $verdict->check?->checkedAt?->toIso8601String(),
        ]);
    }

    private function input(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
