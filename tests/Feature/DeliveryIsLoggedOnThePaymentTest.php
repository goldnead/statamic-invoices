<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Facades\PaymentLog;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;

/**
 * A delivered invoice leaves a line in the payment's communication log.
 *
 * The seam is the payments addon's `PaymentLog` facade, asked by string and
 * only where it exists. The stub records what was handed over; the real thing
 * writes a row the payments detail screen shows.
 */
class DeliveryIsLoggedOnThePaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(PaymentLog::class)) {
            require_once __DIR__.'/../Fixtures/PaymentLogStub.php';
        }

        if (! property_exists(PaymentLog::class, 'mails')) {
            $this->markTestSkipped('the real payments facade is installed; this test drives the stub');
        }

        PaymentLog::$mails = [];

        $this->app->instance(PdfRenderer::class, new class implements PdfRenderer
        {
            public function render(Invoice $invoice): string
            {
                return '%PDF-1.4 stub';
            }
        });

        Mail::fake();
        config(['invoices.delivery.subject' => 'Rechnung :number von Nordlicht']);
    }

    private function invoice(array $overrides = []): Invoice
    {
        // A real payments row: `invoices.payment_id` is a foreign key, and the
        // in-memory SQLite here has constraints switched on.
        $payment = Payment::create([
            'provider' => 'fake', 'provider_id' => 'tr_'.bin2hex(random_bytes(4)), 'product' => 'kurs',
            'amount_cent' => 1190, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'maria@example.com', 'paid_at' => now(),
        ]);

        return Invoice::create(array_merge([
            'brand_id' => 0,
            'number' => 'R-2026-0007',
            'kind' => 'invoice',
            'payment_id' => $payment->id,
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => 'Maria Beispiel',
            'buyer_email' => 'maria@example.com',
            'net_cent' => 1000,
            'tax_cent' => 190,
            'gross_cent' => 1190,
        ], $overrides));
    }

    #[Test]
    public function a_sent_invoice_is_logged_with_its_number_and_subject(): void
    {
        $invoice = $this->invoice();

        $this->assertTrue(app(InvoiceDelivery::class)->send($invoice));

        $this->assertCount(1, PaymentLog::$mails);

        $entry = PaymentLog::$mails[0];

        $this->assertSame($invoice->payment_id, $entry['payment']);
        $this->assertSame('invoice', $entry['kind']);
        $this->assertSame('maria@example.com', $entry['to']);
        $this->assertSame('Rechnung R-2026-0007 von Nordlicht', $entry['subject']);
        $this->assertSame('sent', $entry['status']);
        $this->assertSame('R-2026-0007', $entry['meta']['invoice']);
    }

    #[Test]
    public function an_invoice_without_a_payment_is_not_logged_and_one_that_did_not_go_out_neither(): void
    {
        $this->assertTrue(app(InvoiceDelivery::class)->send($this->invoice(['payment_id' => null, 'number' => 'R-2026-0008'])));
        $this->assertFalse(app(InvoiceDelivery::class)->send($this->invoice(['buyer_email' => '', 'number' => 'R-2026-0009'])));

        $this->assertSame([], PaymentLog::$mails);
    }
}
