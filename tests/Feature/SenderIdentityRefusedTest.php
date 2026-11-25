<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Delivery\InvoiceDelivery;
use Goldnead\Invoices\Models\DeliveryRecord;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Sending\BrandMailer;
use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * Lehnt der Mailer die Absenderidentität einer Marke ab, geht nichts raus, und
 * der Versuch wird sichtbar: mit Rechnungsnummer im Log, ohne Zeile im Status
 * `sending`, die niemand mehr auflöst. War schon ein Fehlversuch da, zählt die
 * Ablehnung als verbrauchter Versuch, sonst käme der Retry alle fünf Minuten.
 */
class SenderIdentityRefusedTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.delivery.retry.after_minutes', 10);
        $app['config']->set('invoices.delivery.retry.max_attempts', 3);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'post@host.test', 'name' => 'Der Host']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PdfRenderer::class, new class implements PdfRenderer
        {
            public function render(Invoice $invoice): string
            {
                return '%PDF-1.4 stub';
            }
        });
    }

    private function mailerRefuses(int $brandId): void
    {
        $this->mock(BrandMailer::class, function ($mock) use ($brandId) {
            $mock->shouldReceive('maySend')->with($brandId)->andReturn(false);
            $mock->shouldNotReceive('send');
        });
    }

    private function rechnung(int $brandId = 7): Invoice
    {
        return Invoice::create([
            'brand_id' => $brandId,
            'number' => 'R-2026-0001',
            'kind' => Invoice::KIND_INVOICE,
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => 'Maria Beispiel',
            'buyer_email' => 'maria.beispiel@example.com',
            'buyer_country' => 'DE',
            'seller' => ['name' => 'Nordlicht Studio', 'email' => 'rechnung@nordlicht.test'],
            'net_cent' => 10000,
            'tax_cent' => 1900,
            'gross_cent' => 11900,
        ]);
    }

    #[Test]
    public function a_refused_sender_sends_nothing_leaves_no_sending_claim_and_says_which_invoice(): void
    {
        $this->mailerRefuses(7);
        Log::spy();

        $rechnung = $this->rechnung(7);

        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));

        $this->assertSame(0, DeliveryRecord::query()->count(), 'Keine Claim-Zeile, auch nicht im Status sending.');
        $this->assertSame(0, DeliveryRecord::query()->where('status', DeliveryRecord::STATUS_SENDING)->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($nachricht, $kontext = []) => $nachricht === 'invoices: R-2026-0001 was not sent; the sender identity was refused.'
                && ($kontext['invoice_id'] ?? null) === $rechnung->getKey()
                && ($kontext['brand_id'] ?? null) === 7)
            ->once();
    }

    #[Test]
    public function a_refusal_after_a_failed_attempt_uses_up_one_attempt_and_stays_failed(): void
    {
        $this->mailerRefuses(7);

        $rechnung = $this->rechnung(7);
        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_FAILED, 'attempts' => 1]);
        DB::table('invoice_deliveries')->where('invoice_id', $rechnung->id)->update(['updated_at' => now()->subMinutes(11)]);

        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));

        $zeile = DeliveryRecord::query()->where('invoice_id', $rechnung->id)->sole();
        // failClaim(track: true): attempts +1, unter max_attempts (3) bleibt es `failed`.
        $this->assertSame(2, $zeile->attempts);
        $this->assertSame(DeliveryRecord::STATUS_FAILED, $zeile->status);
        $this->assertSame('sender identity refused', $zeile->last_error);
    }

    #[Test]
    public function a_refusal_on_the_last_attempt_makes_the_row_failed_final(): void
    {
        $this->mailerRefuses(7);

        $rechnung = $this->rechnung(7);
        DeliveryRecord::create(['invoice_id' => $rechnung->id, 'status' => DeliveryRecord::STATUS_FAILED, 'attempts' => 2]);
        DB::table('invoice_deliveries')->where('invoice_id', $rechnung->id)->update(['updated_at' => now()->subMinutes(21)]);

        $this->assertFalse(app(InvoiceDelivery::class)->send($rechnung));

        $zeile = DeliveryRecord::query()->where('invoice_id', $rechnung->id)->sole();
        // attempts 3 >= max_attempts 3: failClaim setzt failed_final und loggt LOG_FAILED_FINAL.
        $this->assertSame(3, $zeile->attempts);
        $this->assertSame(DeliveryRecord::STATUS_FAILED_FINAL, $zeile->status);
    }
}
