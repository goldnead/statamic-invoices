<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One look at one VAT ID, kept as text.
 *
 * Frozen for the same reason the rate and the seller's address are frozen: the
 * answer VIES gives today is not the answer it gives in three years, and the
 * invoice has to be readable against the law as it stood when it was written.
 * § 14a UStG wants the buyer's number on the document; a tax office asking
 * whether it was confirmed wants to know *when*, and *by whom*.
 *
 * So this carries the verdict, the moment, the service that gave it, and
 * whatever reference that service handed back — and no behaviour. The request
 * id matters more than it looks: VIES returns one per qualified enquiry, and it
 * is the only thing that turns "we checked" into something a third party can
 * follow.
 */
final class VatIdCheck
{
    public function __construct(
        public readonly string $vatId,
        public readonly VatIdStatus $status,
        public readonly ?CarbonInterface $checkedAt = null,
        public readonly ?string $service = null,
        public readonly ?string $requestId = null,
        public readonly ?string $name = null,
        public readonly ?string $address = null,
        /** Why the check could not be made, when it could not. Never shown to a buyer. */
        public readonly ?string $failure = null,
    ) {}

    public static function unchecked(string $vatId): self
    {
        return new self(vatId: $vatId, status: VatIdStatus::Unchecked);
    }

    /**
     * The service could not be reached, or answered with something unusable.
     *
     * Carries a timestamp even though nothing was confirmed: "we tried at 14:02
     * and got a timeout" is a different fact from "nobody ever tried", and the
     * list of outstanding checks is built out of exactly that difference.
     */
    public static function pending(string $vatId, string $service, string $failure, ?CarbonInterface $at = null): self
    {
        return new self(
            vatId: $vatId,
            status: VatIdStatus::Pending,
            checkedAt: $at ?? Carbon::now(),
            service: $service,
            failure: $failure,
        );
    }

    public function isValid(): bool
    {
        return $this->status === VatIdStatus::Valid;
    }

    /**
     * The shape that goes onto a payment and comes back off it.
     *
     * A plain array rather than serialisation of the object: it travels through
     * a JSON column on a payment written by another package, and a class name in
     * there would tie that package's rows to this one's namespace.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'vat_id' => $this->vatId,
            'status' => $this->status->value,
            'checked_at' => $this->checkedAt?->toIso8601String(),
            'service' => $this->service,
            'request_id' => $this->requestId,
            'name' => $this->name,
            'address' => $this->address,
            'failure' => $this->failure,
        ], fn ($v) => $v !== null);
    }

    /**
     * Back from that shape.
     *
     * Returns null rather than a guess when the stored value carries no status
     * this version knows. A row written by a later release must not quietly read
     * as "unchecked" here — that would turn an unknown verdict into a stated one.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        $status = VatIdStatus::tryFromMixed($stored['status'] ?? null);
        $vatId = $stored['vat_id'] ?? null;

        if ($status === null || ! is_string($vatId) || $vatId === '') {
            return null;
        }

        $checkedAt = null;

        if (is_string($stored['checked_at'] ?? null) && $stored['checked_at'] !== '') {
            try {
                $checkedAt = Carbon::parse($stored['checked_at']);
            } catch (Throwable) {
                // A timestamp that will not parse is dropped rather than defaulted to
                // now(): "checked just now" is the one wrong answer that looks right.
                $checkedAt = null;
            }
        }

        return new self(
            vatId: $vatId,
            status: $status,
            checkedAt: $checkedAt,
            service: self::string($stored['service'] ?? null),
            requestId: self::string($stored['request_id'] ?? null),
            name: self::string($stored['name'] ?? null),
            address: self::string($stored['address'] ?? null),
            failure: self::string($stored['failure'] ?? null),
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
