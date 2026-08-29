<?php

namespace Goldnead\Invoices\Sending;

use Goldnead\BrandContext\Sending\BrandSenderIdentity as BrandContextIdentity;
use Goldnead\Invoices\Contracts\SenderIdentityResolver;

/**
 * The shipped resolver: whatever the brand declares under `settings.mail`, and
 * the host's own configuration when no brand declares anything.
 *
 * A single-brand installation therefore sends exactly as it did before — the
 * invoice leaves through `config('mail.from')`, unchanged.
 */
class BrandSenderIdentity extends BrandContextIdentity implements SenderIdentityResolver {}
