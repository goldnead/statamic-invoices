<?php

namespace Goldnead\Invoices\Contracts;

use Goldnead\BrandContext\Contracts\SenderIdentityResolver as BrandContextResolver;

/**
 * An empty sub-interface, on purpose.
 *
 * The contract lives in statamic-brand-context, where the addons agreed on it
 * rather than keeping a copy each. What this adds is a name a host can rebind
 * for *this* package alone — and here that is not hypothetical: an invoice is
 * the one piece of post that must carry the legal seller's identity, which a
 * host may well want answered differently from its marketing mail.
 */
interface SenderIdentityResolver extends BrandContextResolver {}
