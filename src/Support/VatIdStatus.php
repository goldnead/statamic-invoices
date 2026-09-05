<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Support;

/**
 * What is actually known about a buyer's VAT ID at the moment it was looked at.
 *
 * Four states, and the distance between them is the whole point of this file.
 * "The format matches" and "the service confirmed it" are not the same claim,
 * and an invoice that prints the reverse-charge note has to be able to say
 * which of the two it stands on. § 14a UStG asks for the note; it does not
 * excuse a note the seller cannot back up.
 *
 * `Pending` is the one that earns its place. A confirmation service that is
 * down must not stop a purchase — the rule from 25.08. is that an invoice does
 * not fall over because a foreign server did — but the document then says
 * "verification pending" rather than borrowing the wording of a check that
 * never happened. Somebody looks at those later; that is what the Control
 * Panel list is for.
 */
enum VatIdStatus: string
{
    /** The confirmation service answered: this number is valid. */
    case Valid = 'valid';

    /** The confirmation service answered: this number is not valid. */
    case Invalid = 'invalid';

    /** Nobody could be asked. The number was accepted, the check is owed. */
    case Pending = 'pending';

    /** No check was attempted. The format is all that is known. */
    case Unchecked = 'unchecked';

    /**
     * Is this a number the seller may build a reverse-charge invoice on?
     *
     * `Pending` counts, deliberately: the alternative is refusing a paid
     * purchase because Brussels had an outage. What differs is the wording on
     * the document, not whether it exists.
     */
    public function permitsReverseCharge(): bool
    {
        return $this === self::Valid || $this === self::Pending;
    }

    /**
     * May a buyer with this status enter the checkout at all?
     *
     * Same answer, different question, and they are kept apart on purpose: one
     * is about the document, the other about the sale. They agree today. If
     * they ever stop agreeing, two methods can say so and one cannot.
     */
    public function permitsPurchase(): bool
    {
        return $this === self::Valid || $this === self::Pending;
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
