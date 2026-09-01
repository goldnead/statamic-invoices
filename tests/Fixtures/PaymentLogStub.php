<?php

namespace Goldnead\StatamicPayments\Facades;

/**
 * Stand-in for statamic-payments' `PaymentLog` facade (1.16+), which the
 * installed test copy of that package may predate. Loaded only when the real
 * class is absent; records what `InvoiceDelivery` hands over.
 */
class PaymentLog
{
    /** @var list<array<string, mixed>> */
    public static array $mails = [];

    public static function mail(int|object $payment, string $kind, string $to, ?string $subject = null, string $status = 'sent', array $meta = [], ?string $reference = null): ?object
    {
        self::$mails[] = compact('payment', 'kind', 'to', 'subject', 'status', 'meta', 'reference');

        return (object) end(self::$mails);
    }
}
