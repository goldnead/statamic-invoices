<?php

namespace Goldnead\Invoices\Delivery;

use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\TaxResult;

/**
 * Holds back an invoice whose zero tax nobody decided.
 *
 * THE CASE THAT MADE THIS NECESSARY (adriangoldner.com, 2026)
 * -----------------------------------------------------------
 * The site configured `standard` for its coaching packages. `TaxRules` still
 * has a branch no configuration reaches: a third country and a product with
 * `digital => false` fall into the export rule and come out at 0 % with
 * "Steuerfreie Ausfuhrlieferung." For a video session that is a statement
 * about the law that nobody made.
 *
 * **This class decides no tax question.** It compares the written document
 * with the decision in the configuration. A line at 0 % is a deviation when
 * its product has a tax class, that class is not an exemption, and the zero
 * did not come from the class's own rate (`standard` at a zone rate of 0), from
 * the exemption, or from § 19. What is left is a zero that a cross-border rule
 * produced. `accept` names the rules whose zero is taken as decided; by default
 * all four cross-border rules TaxRules applies, so a site narrows it to hold
 * (adriangoldner.com accepts only reverse charge and the intra-community
 * supply, which arise on a VAT ID the register confirmed).
 *
 * The class is looked up as TaxRules looks it up: the product's entry in
 * `tax.product_classes`, then `tax.default_product_class`. With a default
 * class set, every product counts as having a rated class, including one the
 * site never listed; that is the same answer TaxRules gave when it wrote the
 * line, so the guard holds where the rate came from that default, too.
 *
 * A line written before `tax_mechanism` existed has no rule stored; then only
 * the § 19 switch speaks for it, as in the site's original check.
 *
 * Held rather than sent, because an invoice cannot change: a wrong one in the
 * buyer's mailbox can only be cancelled, a wrong one in the database can be
 * corrected before anyone has seen it.
 */
class ZeroTaxGuard
{
    /** Alertable log message. Stable: monitoring matches on it. */
    public const LOG_HELD = 'invoices: invoice held back, zero tax where the product\'s tax class carries a rate';

    /** The reason stored on the delivery record and in the payment's log. */
    public const REASON = 'unexpected_zero_tax';

    /** Zeros that are the decision itself, never a deviation. */
    protected const DECIDED = [
        TaxResult::MECHANISM_STANDARD,
        TaxResult::MECHANISM_EXEMPT,
        TaxResult::MECHANISM_SMALL_BUSINESS,
    ];

    public function enabled(): bool
    {
        return (bool) config('invoices.delivery.zero_tax_guard.enabled', true);
    }

    /**
     * The lines that contradict the configuration; empty when the invoice may go.
     *
     * @return list<array{product: string, name: string, tax_class: string, tax_mechanism: string|null}>
     */
    public function deviations(Invoice $invoice): array
    {
        if (! $this->enabled() || $invoice->isCreditNote()) {
            return [];
        }

        $accepted = array_values(array_filter(
            (array) config('invoices.delivery.zero_tax_guard.accept', []),
            'is_string',
        ));

        $found = [];

        foreach ($invoice->items as $line) {
            if ((int) $line->tax_rate_bp !== 0 || (int) $line->net_cent === 0) {
                continue;
            }

            $mechanism = is_string($line->tax_mechanism) && $line->tax_mechanism !== '' ? $line->tax_mechanism : null;

            if ($mechanism !== null && (in_array($mechanism, self::DECIDED, true) || in_array($mechanism, $accepted, true))) {
                continue;
            }

            if ($mechanism === null && (bool) config('invoices.tax.small_business.enabled', false)) {
                continue;
            }

            $handle = (string) $line->product;
            $class = $this->classFor($handle);

            if ($class === null || $this->isExemption($class)) {
                continue;
            }

            $found[] = [
                'product' => $handle,
                'name' => (string) $line->name,
                'tax_class' => $class,
                'tax_mechanism' => $mechanism,
            ];
        }

        return $found;
    }

    /** The same lookup `TaxRules` makes: the product's own class, then the default. */
    protected function classFor(string $handle): ?string
    {
        $classes = config('invoices.tax.product_classes', []);
        $class = $handle !== '' && is_array($classes) ? ($classes[$handle] ?? null) : null;

        if (! is_string($class) || trim($class) === '') {
            $class = config('invoices.tax.default_product_class');
        }

        return is_string($class) && trim($class) !== '' ? trim($class) : null;
    }

    protected function isExemption(string $class): bool
    {
        $exemptions = config('invoices.tax.exemptions', []);

        return is_array($exemptions) && array_key_exists($class, $exemptions) && $exemptions[$class] !== null;
    }
}
