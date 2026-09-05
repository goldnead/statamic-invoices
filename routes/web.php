<?php

use Goldnead\Invoices\Http\Controllers\BuyerCheckController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| One route, and it answers a question
|--------------------------------------------------------------------------
|
| The checkout form asks whether this buyer would get through, so a missing
| VAT ID surfaces while the buyer can still fix it rather than after the
| payment has started.
|
| It is deliberately not the enforcement point. That is the middleware
| `invoices.business-buyer`, which the host puts on its own checkout route —
| a rule that only lives in a front-end request is a rule anybody can post
| past. Both use the same class, so the two can never disagree.
|
| Rate-limited, because it reaches a foreign service on the caller's behalf.
| Without a limit this endpoint is a way to make the seller's server hammer
| VIES on somebody else's behalf.
|
*/

Route::post('/!/invoices/buyer-check', BuyerCheckController::class)
    ->middleware('throttle:20,1')
    ->name('invoices.buyer-check');
