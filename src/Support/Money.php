<?php

namespace Goldnead\Invoices\Support;

/**
 * An amount, written the way the invoice writes it.
 *
 * Extracted from {@see Renderer} the moment a second place needed it — the mail
 * that carries the document names the total in its first sentence. Two
 * `number_format` calls with two hand-written currency symbols is how a mail
 * ends up announcing "119,00 €" over an attachment that says "119,00 CHF".
 */
final class Money
{
    public static function format(int $cent, ?string $currency): string
    {
        return number_format($cent / 100, 2, ',', '.').' '.self::symbol($currency);
    }

    public static function symbol(?string $currency): string
    {
        return match (strtoupper((string) $currency)) {
            'EUR' => '€',
            'CHF' => 'CHF',
            'GBP' => '£',
            'USD' => '$',
            default => (string) $currency,
        };
    }
}
