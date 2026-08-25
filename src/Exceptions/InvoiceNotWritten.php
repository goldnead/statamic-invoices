<?php

namespace Goldnead\Invoices\Exceptions;

/**
 * The invoice could not be written, and the reason needs a person.
 *
 * A missing tax rule, a product that does not say whether it is digital, no
 * current brand — each of them is a decision somebody has to make, and none of
 * them is fixed by trying again.
 *
 * The common parent exists so a caller can catch all of them at once. That
 * matters more than it looks: the listener runs inside somebody else's payment
 * flow, and an exception escaping it would roll back a fulfilment and have the
 * provider deliver the whole webhook again — for a problem no retry solves.
 */
abstract class InvoiceNotWritten extends \RuntimeException {}
