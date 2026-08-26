<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Exceptions\DoesNotMatchThePayment;
use Goldnead\Invoices\Exceptions\PriceBasisUndecided;
use Goldnead\Invoices\Exceptions\RateUndetermined;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * Whether catalogue prices already contain tax is a decision, not a default.
 *
 * 1900 is either €19.00 gross with €3.03 of tax inside it, or €19.00 net plus
 * €3.61 on top. Both invoices are internally consistent, every line adds up in
 * both, and only one of them matches the money that actually arrived.
 *
 * The old default was `false`. For an addon whose audience sells to German
 * consumers that is the wrong guess: the price a buyer is shown is the final
 * price including VAT, so 19 € in a catalogue means 19 € gross — and the
 * invoice would have shown €22.61 against a payment of €19.00.
 *
 * What makes it worth refusing over rather than defaulting better: nothing
 * downstream contradicts the wrong answer. It is found by a tax adviser or by
 * a customer, months later, and never by the operator.
 */
class ThePriceBasisIsNotGuessedTest extends TestCase
{
    private function steuer(array $ueberschreibungen = []): void
    {
        config()->set('invoices.tax', array_merge([
            'merchant_country' => 'DE',
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard', 'zugabe' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ], $ueberschreibungen));
    }

    private function zahlung(): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs',
            'amount_cent' => 1900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
            'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE',
            'paid_at' => now(),
        ]);
    }

    #[Test]
    public function no_invoice_is_written_until_somebody_has_decided(): void
    {
        $this->steuer();

        $this->expectException(PriceBasisUndecided::class);

        try {
            app(InvoiceWriter::class)->forPayment($this->zahlung());
        } finally {
            $this->assertSame(0, Invoice::count(), 'und kein halbes Dokument bleibt liegen');
        }
    }

    #[Test]
    public function gross_prices_produce_the_amount_that_was_paid(): void
    {
        $this->steuer(['prices_include_tax' => true]);

        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());

        // The buyer paid €19.00, so the invoice says €19.00.
        $this->assertSame(1900, $rechnung->gross_cent);
        $this->assertSame(1597, $rechnung->net_cent);
        $this->assertSame(303, $rechnung->tax_cent);
    }

    /**
     * The other answer, and what it reveals.
     *
     * Written expecting €22.61, and refused instead — by the reconciliation
     * guard, which is the outcome that taught me something. Treating catalogue
     * prices as net means the invoice totals more than the payment did, because
     * nothing in this family adds tax at checkout: `statamic-payments` charges
     * the catalogue amount and knows nothing about VAT.
     *
     * So `prices_include_tax => false` is not merely the wrong default here —
     * for an invoice derived from a payment it cannot be right at all until
     * something charges the tax it assumes. The option stays, because a host
     * may one day do exactly that; what changes is that a misconfigured
     * installation now finds out on its first invoice instead of at the next
     * audit.
     */
    #[Test]
    public function net_prices_do_not_add_up_to_what_was_paid(): void
    {
        $this->steuer(['prices_include_tax' => false]);

        $this->expectException(DoesNotMatchThePayment::class);

        try {
            app(InvoiceWriter::class)->forPayment($this->zahlung());
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    /**
     * The guard the case above runs into, stated on its own.
     *
     * An invoice built from a payment has exactly one witness outside its own
     * arithmetic: the money. Everything else is consistent by construction, so
     * a wrong rate produces a wrong document that looks exactly like a right
     * one.
     */
    #[Test]
    public function a_document_that_does_not_match_the_money_is_refused(): void
    {
        $this->steuer(['prices_include_tax' => true]);

        $zahlung = $this->zahlung();

        // Line items that do not sum to the payment. This is the drift that
        // can actually happen: a bump added to the order but never charged, a
        // webhook replaying an older basket, a discount applied to the total
        // and not to the lines.
        //
        // Written first as "change the payment amount", which turned out to be
        // impossible: without line items the invoice line is derived FROM the
        // payment, so it agrees with it by construction. The check only has
        // something to say where the two are computed separately — which is
        // exactly where drift lives.
        config()->set('statamic-payments.products.zugabe', [
            'name' => 'Zugabe', 'amount_cent' => 500, 'digital' => true,
        ]);

        $zahlung->items()->createMany([
            ['product' => 'kurs', 'name' => 'Kurs', 'amount_cent' => 1900, 'quantity' => 1, 'kind' => 'primary'],
            ['product' => 'zugabe', 'name' => 'Zugabe', 'amount_cent' => 500, 'quantity' => 1, 'kind' => 'bump'],
        ]);

        $this->expectException(DoesNotMatchThePayment::class);

        try {
            app(InvoiceWriter::class)->forPayment($zahlung->fresh(['items']));
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }

    /**
     * The refusal must not reach the sellers for whom the question is moot.
     */
    #[Test]
    public function a_small_business_seller_needs_no_answer(): void
    {
        $this->steuer(['small_business' => ['enabled' => true]]);

        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung());

        // Under § 19 no tax is shown at all, so net equals gross either way and
        // there is nothing to decide.
        $this->assertNotNull($rechnung);
        $this->assertSame(1900, $rechnung->gross_cent);
        $this->assertSame(0, $rechnung->tax_cent);
    }

    #[Test]
    public function an_undetermined_rate_still_says_so_and_not_this(): void
    {
        // Both are unanswered questions, and the reader must be sent after the
        // right one. A missing rate is the older and more specific complaint.
        $this->steuer(['zones' => []]);

        try {
            app(InvoiceWriter::class)->forPayment($this->zahlung());
            $this->fail('eine Rechnung ohne Satz darf nicht entstehen');
        } catch (PriceBasisUndecided $e) {
            $this->fail('falsche Beschwerde: der Satz fehlt, nicht die Preisbasis');
        } catch (RateUndetermined $e) {
            $this->assertSame(0, Invoice::count());
        }
    }
}
