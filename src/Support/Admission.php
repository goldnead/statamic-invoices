<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Support;

/**
 * Whether this buyer may buy, and what the invoice would then say.
 *
 * Carries the refusal as a sentence the buyer reads, not as a code the caller
 * has to translate. A checkout answering "invalid_vat_id" has told the buyer
 * nothing they can act on, and the commonest reason for a refused VAT ID is a
 * typo in it — so the sentence names the number and says what to do.
 *
 * The code is there as well, for a log and for a test that should not break
 * when the wording is improved.
 */
final class Admission
{
    private function __construct(
        public readonly bool $admitted,
        public readonly ?TaxZone $zone = null,
        public readonly ?VatIdCheck $check = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
    ) {}

    /**
     * @param  TaxZone|null  $zone  null for a buyer who has no zone in this model —
     *                              a consumer, admitted because the installation has turned the
     *                              business-only rule off. Admitted and zoneless are two different
     *                              facts, and collapsing them would make a consumer look domestic.
     */
    public static function admit(?TaxZone $zone, ?VatIdCheck $check = null): self
    {
        return new self(admitted: true, zone: $zone, check: $check);
    }

    public static function refuse(string $code, string $message, ?VatIdCheck $check = null): self
    {
        return new self(admitted: false, check: $check, code: $code, message: $message);
    }

    public function refused(): bool
    {
        return ! $this->admitted;
    }
}
