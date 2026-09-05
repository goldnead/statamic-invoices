<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Http\Middleware;

use Closure;
use Goldnead\Invoices\Support\Admission;
use Goldnead\Invoices\Support\BuyerAdmission;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate, on the host's own checkout route.
 *
 * This is where "no EU sale without a confirmed VAT ID" stops being a rule and
 * becomes a thing that happens. A rule enforced by the front end alone is
 * enforced by whoever has not disabled JavaScript.
 *
 * It sits here rather than inside statamic-payments on purpose: the checkout
 * package takes money and knows nothing about § 3a UStG, and teaching it would
 * put a tax rule where nobody looks for one. A host attaches this to its own
 * checkout route and the two packages stay apart:
 *
 *     Route::post('/checkout', CheckoutController::class)
 *         ->middleware('invoices.business-buyer');
 *
 * **What it hands on.** On admission the frozen check is merged into the request
 * under `vat_id_check`, so the controller behind it can put it straight into the
 * payment's meta and nothing asks the confirmation service twice. That value
 * comes from this class, never from the buyer — a client posting its own
 * `vat_id_check` has it overwritten here, which is the difference between a gate
 * and a suggestion.
 */
class RequireBusinessBuyer
{
    public function __construct(private readonly BuyerAdmission $admission) {}

    public function handle(Request $request, Closure $next): Response
    {
        $verdict = $this->admission->for(
            country: $this->input($request, 'country'),
            vatId: $this->input($request, 'vat_id'),
            company: $this->input($request, 'company'),
            businessConfirmed: $request->boolean('business_confirmed'),
        );

        if ($verdict->refused()) {
            return $this->refuse($request, $verdict);
        }

        // Replaced, not defaulted. `merge` on a key the request already carries
        // overwrites it, and that is exactly what is wanted: whatever the client
        // sent under this name is a claim, and this is the answer.
        $request->merge(['vat_id_check' => $verdict->check?->toArray()]);

        return $next($request);
    }

    private function refuse(Request $request, Admission $verdict): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'admitted' => false,
                'code' => $verdict->code,
                'message' => $verdict->message,
            ], 422);
        }

        // 422 for a form post as well, not a 302 that reads as success in a log.
        // The errors bag is what a Blade checkout renders; the status code is what
        // an uptime check and a test see, and the two should agree.
        return back()
            ->withInput($request->except(['vat_id_check']))
            ->withErrors([$this->fieldFor($verdict->code) => (string) $verdict->message])
            ->setStatusCode(422);
    }

    /**
     * Which field the message belongs under, so the checkout can put it next to
     * the input the buyer has to fix rather than at the top of the page.
     */
    private function fieldFor(?string $code): string
    {
        return match ($code) {
            'country_missing' => 'country',
            'company_missing' => 'company',
            'business_not_confirmed' => 'business_confirmed',
            default => 'vat_id',
        };
    }

    private function input(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
