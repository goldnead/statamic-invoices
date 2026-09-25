<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Integrations\InvoiceBridge;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Portal\Mail\PortalLinkMail;
use Goldnead\StatamicPayments\Support\Invoices as InvoiceSources;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The invoice reaches the buyer through the payments portal.
 *
 * payments asks an invoice object by shape, never by type: the first of
 * `pdf()`, `toPdf()`, `download()`, `html()`, `render()` that exists is the
 * document (`InvoiceBridge::producerOn()`). The model had none of them, so the
 * portal never offered a download, and nothing in either suite noticed,
 * because each side was only ever tested against a stand-in for the other.
 *
 * This file puts the two real packages next to each other: the real writer,
 * the real renderer, the real bridge, the real portal routes.
 */
class TheBuyerDownloadsTheInvoiceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    private function zahlung(string $email, string $id): Payment
    {
        return Payment::create([
            'provider' => 'fake',
            'provider_id' => $id,
            'product' => 'kurs',
            'amount_cent' => 11900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => $email,
            'name' => 'Anna Beispiel',
            'country' => 'DE',
            'paid_at' => now(),
        ]);
    }

    #[Test]
    public function the_invoice_is_its_own_pdf(): void
    {
        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung('anna@example.de', 'tr_a'));

        $this->assertInstanceOf(Invoice::class, $rechnung);

        $bytes = $rechnung->pdf();

        $this->assertSame('%PDF-', substr($bytes, 0, 5), 'pdf() liefert keine PDF-Datei');
        // Dieselbe Datei wie der Renderer, nicht eine zweite Fassung daneben.
        $this->assertSame(md5(app(PdfRenderer::class)->render($rechnung)), md5($bytes));
    }

    #[Test]
    public function pdf_goes_through_the_renderer_the_host_bound(): void
    {
        $this->app->bind(PdfRenderer::class, fn () => new class implements PdfRenderer
        {
            public function render(Invoice $invoice): string
            {
                return '%PDF-1.4 eigene Maschine '.$invoice->number;
            }
        });

        $rechnung = app(InvoiceWriter::class)->forPayment($this->zahlung('anna@example.de', 'tr_b'));

        $this->assertSame('%PDF-1.4 eigene Maschine '.$rechnung->number, $rechnung->pdf());
    }

    #[Test]
    public function the_portal_offers_the_download_and_hands_over_the_pdf_to_its_owner_only(): void
    {
        if (! class_exists(InvoiceBridge::class) || ! Route::has('statamic-payments.portal.invoice')) {
            $this->markTestSkipped('Diese payments-Fassung hat noch kein Kundenportal mit Rechnung.');
        }

        // Die echte Bruecke, von Hand eingehaengt: testbench feuert die
        // booted-Callbacks eines Addons nicht zuverlaessig, und ein zweiter
        // Eintrag schadet nicht, der erste, der antwortet, gewinnt.
        InvoiceSources::forgetSources();
        InvoiceSources::extend(new InvoiceBridge);

        $anna = $this->zahlung('anna@example.de', 'tr_c');
        $boris = $this->zahlung('boris@example.de', 'tr_d');
        $rechnung = app(InvoiceWriter::class)->forPayment($anna);
        app(InvoiceWriter::class)->forPayment($boris);

        $this->anmelden('anna@example.de');

        $download = route('statamic-payments.portal.invoice', ['payOrder' => $anna->getKey()]);

        $this->get(route('statamic-payments.portal.order', ['payOrder' => $anna->getKey()]))
            ->assertOk()
            ->assertSee($rechnung->number)
            ->assertSee($download, false);

        $antwort = $this->get($download)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment;', (string) $antwort->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-', substr((string) $antwort->getContent(), 0, 5));

        // Die Rechnung von Boris ist fuer Anna nicht zu haben, auch nicht per URL.
        $this->get(route('statamic-payments.portal.invoice', ['payOrder' => $boris->getKey()]))
            ->assertNotFound();

        InvoiceSources::forgetSources();
    }

    private function anmelden(string $email): void
    {
        Mail::fake();

        $this->post(route('statamic-payments.portal.request.send'), ['email' => $email]);

        $url = null;

        Mail::assertSent(PortalLinkMail::class, function (PortalLinkMail $mail) use (&$url) {
            $url ??= $mail->url;

            return true;
        });

        $this->get((string) $url)->assertRedirect(route('statamic-payments.portal.show'));
    }
}
