<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\Renderer;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * The PDF, and the promise that it is the same one every time.
 *
 * An invoice is kept for ten years and may be fetched again on the last day of
 * them. If the second copy differs from the first, the buyer holds two versions
 * of a document that legally has only one — and the difference is not
 * discovered by anybody until it is a question at an audit.
 *
 * So this file asks the same thing from three directions: the bytes do not
 * move, the configuration underneath may move all it likes, and the engine that
 * produces them is the host's to replace.
 */
class TheInvoiceAsAFileTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard', 'noten' => 'reduced'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900, 'reduced' => 700]]],
        ]);
    }

    private function rechnung(array $werte = []): Invoice
    {
        $zahlung = Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs',
            'amount_cent' => 11900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com',
            'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE',
            'paid_at' => now(),
        ], $werte));

        return app(InvoiceWriter::class)->forPayment($zahlung);
    }

    #[Test]
    public function two_renderings_of_one_invoice_are_byte_for_byte_the_same(): void
    {
        // Der Kern der Zusage. dompdf stempelt sonst die Wanduhr
        // (`CreationDate`, `ModDate`) und eine gewuerfelte Dokument-ID in jede
        // Datei; ohne die Behandlung in `DompdfRenderer` waeren zwei Abrufe
        // derselben Rechnung zwei verschiedene Dateien.
        $rechnung = $this->rechnung();
        $renderer = app(PdfRenderer::class);

        $erste = $renderer->render($rechnung);
        sleep(1);
        $zweite = $renderer->render($rechnung->fresh(['items']));

        $this->assertSame('%PDF-', substr($erste, 0, 5), 'das ist keine PDF-Datei');
        $this->assertSame(
            md5($erste),
            md5($zweite),
            'zwei Erzeugungen derselben Rechnung ergaben zwei verschiedene Dateien',
        );
    }

    #[Test]
    public function the_file_is_read_off_the_row_and_not_recalculated(): void
    {
        // Alles, was die Rechnung sagt, ist beim Schreiben eingefroren worden.
        // Wird danach die halbe Konfiguration umgestellt — Satz, Absender,
        // Preisbasis, Nummernkreis — darf sich an der Datei kein Byte ruehren.
        // Genau das ist der Unterschied zwischen „gespeicherte Werte" und
        // „dieselbe Rechnung nochmal gerechnet".
        $rechnung = $this->rechnung();
        $renderer = app(PdfRenderer::class);

        $vorher = $renderer->render($rechnung);

        config([
            'invoices.tax.zones' => [['countries' => ['DE'], 'rates' => ['standard' => 700]]],
            'invoices.tax.prices_include_tax' => false,
            'invoices.seller.name' => 'Ein ganz anderer Verkäufer',
            'invoices.seller.address' => 'Anderswo 9',
            'invoices.number.prefix' => 'XX',
        ]);

        $this->assertSame(md5($vorher), md5($renderer->render($rechnung->fresh(['items']))));
    }

    #[Test]
    public function the_legal_texts_stay_exchangeable(): void
    {
        // `tax.texts` und `tax.legal_bases` sind austauschbar und muessen es
        // bleiben: der Wortlaut ist die Sache des Betreibers und seines
        // Steuerberaters. Er wird beim Schreiben als Text auf die Rechnung
        // gelegt — nicht in die Vorlage und nicht in die Druckmaschine, sonst
        // koennte ihn danach niemand mehr aendern.
        config([
            'invoices.tax.small_business.enabled' => true,
            'invoices.tax.texts.small_business' => 'Kein Ausweis von Umsatzsteuer, § 19 UStG.',
        ]);

        $alte = $this->rechnung();

        config(['invoices.tax.texts.small_business' => 'Umsatzsteuer wird nicht berechnet (§ 19 UStG).']);

        $neue = $this->rechnung();

        $this->assertSame('Kein Ausweis von Umsatzsteuer, § 19 UStG.', $alte->tax_note);
        $this->assertSame('Umsatzsteuer wird nicht berechnet (§ 19 UStG).', $neue->tax_note);

        // Und der geaenderte Wortlaut steht auch wirklich im neuen Dokument,
        // statt nur in der Spalte.
        $this->assertStringContainsString(
            'Umsatzsteuer wird nicht berechnet (§ 19 UStG).',
            app(Renderer::class)->html($neue),
        );
        $this->assertStringContainsString(
            'Kein Ausweis von Umsatzsteuer, § 19 UStG.',
            app(Renderer::class)->html($alte),
        );
    }

    #[Test]
    public function two_different_invoices_are_two_different_files(): void
    {
        // Die Gegenprobe zur Reproduzierbarkeit. Ohne sie wuerde ein Renderer,
        // der immer dieselbe leere Seite zurueckgibt, jeden Test oben bestehen.
        $renderer = app(PdfRenderer::class);

        $eine = $renderer->render($this->rechnung());
        $andere = $renderer->render($this->rechnung(['amount_cent' => 4900]));

        $this->assertNotSame(md5($eine), md5($andere));
    }

    #[Test]
    public function the_host_may_bind_its_own_engine(): void
    {
        // Der Renderer haengt an einem Contract im Container, nicht an einer
        // festen Klasse: wer schon einen Browser oder eine Druckerei hat, nimmt
        // die eigene.
        $this->app->bind(PdfRenderer::class, fn () => new class implements PdfRenderer
        {
            public function render(Invoice $invoice): string
            {
                return '%PDF-1.4 vom Haus des Betreibers, '.$invoice->number;
            }
        });

        $this->assertStringContainsString(
            'vom Haus des Betreibers',
            app(PdfRenderer::class)->render($this->rechnung()),
        );
    }
}
