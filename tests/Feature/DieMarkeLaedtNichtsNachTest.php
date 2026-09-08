<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Mail\InvoiceMail;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\MarkenBild;
use Goldnead\Invoices\Support\Renderer;
use Goldnead\Invoices\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Die Marke auf Rechnung und Rechnungsmail — und die Regel, die dabei gilt.
 *
 * **Beim Anzeigen wird nichts nachgeladen.** Nicht aus Prinzipienreiterei,
 * sondern aus zwei handfesten Gruenden:
 *
 * - Mail-Clients blockieren entfernte Bilder standardmaessig. Ein Logo per
 *   `https://` ist beim ersten Oeffnen ein leerer Kasten — der erste Eindruck
 *   nach dem Kauf.
 * - Eine Rechnung ist ein Zehn-Jahre-Dokument. Was sie beim Rendern nachlaedt,
 *   gibt es in zehn Jahren vielleicht nicht mehr.
 *
 * Die Tests hier sind die Wache dafuer. Wer das Branding spaeter „schoener"
 * macht, indem er ein Bild verlinkt oder eine Schrift von Google holt, faellt
 * hier auf.
 */
class DieMarkeLaedtNichtsNachTest extends TestCase
{
    private string $logo;

    /**
     * Ob die Gegenseite ueberhaupt da ist.
     *
     * `BrandIdentity` kam in brand-context 1.13. Dieses Paket fuehrt
     * brand-context als optionale Abhaengigkeit und laeuft auch gegen aeltere
     * Fassungen — dann greifen die Vorgaben, und die zwei Marken-Faelle unten
     * haetten nichts zu pruefen.
     *
     * Uebersprungen statt gruen gelogen: ein Test, der ohne die Gegenseite
     * trotzdem besteht, belegt nichts und faellt spaeter niemandem auf.
     */
    private function markeVerfuegbar(): bool
    {
        return class_exists('Goldnead\\BrandContext\\Support\\BrandIdentity');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->logo = sys_get_temp_dir().'/invoices-marke-'.getmypid().'.svg';
        file_put_contents($this->logo, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32"/></svg>');

        config(['brand-context.identity' => [
            'name' => 'Testmarke',
            'logo' => $this->logo,
            'ink' => '#0a0f1e',
            'accent' => '#0f1629',
            'paper' => '#eef2f8',
        ]]);

        // In einer echten Installation legt brand-context sie selbst an; hier
        // steht sie, damit `Brand::default()` nicht an einer fehlenden Tabelle
        // scheitert.
        if (! Schema::hasTable('brands')) {
            Schema::create('brands', function ($t) {
                $t->id();
                $t->string('handle')->unique();
                $t->string('name');
                $t->boolean('is_default')->default(false);
                $t->json('settings')->nullable();
                $t->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->logo);

        parent::tearDown();
    }

    private function rechnung(): Invoice
    {
        return Invoice::create([
            'brand_id' => 0,
            'number' => 'RE2026-09-001',
            'issued_at' => now(),
            'currency' => 'EUR',
            'buyer_name' => 'Nordlicht Studio GmbH',
            'seller' => ['name' => 'Adrian Goldner', 'email' => 'rechnung@example.test'],
            'net_cent' => 49900,
            'tax_cent' => 0,
            'gross_cent' => 49900,
        ]);
    }

    #[Test]
    public function the_invoice_carries_the_brand_and_fetches_nothing(): void
    {
        if (! $this->markeVerfuegbar()) {
            $this->markTestSkipped('brand-context ist aelter als 1.13 und kennt BrandIdentity nicht — dann gelten die Vorgaben, und hier waere nichts zu pruefen.');
        }

        $html = app(Renderer::class)->html($this->rechnung()->load('items'));

        // Da ist sie.
        $this->assertStringContainsString('Testmarke', $html);

        // Als `data:`-Bild, und das ist die Korrektur vom 08.09.2026.
        //
        // Vorher stand hier `assertStringContainsString('<svg', …)` und, eine
        // Zeile darunter, `assertStringNotContainsString('data:', …)` mit der
        // Begruendung „das PDF traegt das SVG als Markup". Beides war gruen und
        // beides war falsch: **dompdf zeichnet ein `<svg>` im HTML nicht.** Es
        // ueberspringt es wortlos, und jede erzeugte Rechnung trug seit
        // Einfuehrung des Brandings nur die Wortmarke. Dieser Test hat den
        // Fehler nicht gefunden, er hat ihn festgeschrieben.
        //
        // Gemessen, nicht vermutet: dieselbe Datei als `data:image/svg+xml;base64`
        // rastert dompdf, als Dateipfad im `src` auch (aber nur mit `chroot`),
        // als roher `;utf8,`-URI gibt es einen leeren Kasten.
        $this->assertStringContainsString(
            'src="data:image/svg+xml;base64,',
            $html,
            'Das Logo steht als eingebettetes Bild im Dokument, nicht als Inline-Markup.',
        );

        // Und nichts davon kommt aus dem Netz. Das ist die Regel, um die es
        // hier geht; `data:` verletzt sie nicht, sondern ist ihre strengste
        // Form: die Bytes stehen im Dokument.
        $this->assertDoesNotMatchRegularExpression('#(src|href)="(https?:)?//#', $html);
        $this->assertStringNotContainsString('@import', $html);
        $this->assertStringNotContainsString('@font-face', $html);
        $this->assertStringNotContainsString('fonts.googleapis', $html);
    }

    #[Test]
    public function the_sent_mail_carries_the_logo_as_an_attachment_not_as_a_link(): void
    {
        if (! $this->markeVerfuegbar()) {
            $this->markTestSkipped('brand-context ist aelter als 1.13 und kennt BrandIdentity nicht — dann gelten die Vorgaben, und hier waere nichts zu pruefen.');
        }

        // **Der echte Weg durch den Transport, nicht `render()`.** Nur hier
        // entscheidet sich, was beim Empfaenger ankommt: `render()` allein
        // erzeugt fuer dasselbe `embed()` einen `data:`-URI, die gesendete Mail
        // dagegen einen CID-Anhang. Ein Test gegen `render()` haette also genau
        // das Gegenteil dessen belegt, was rausgeht.
        config(['mail.default' => 'array']);

        Mail::to('kaeufer@example.test')->send(new InvoiceMail($this->rechnung(), '%PDF-1.4', 'rechnung.pdf'));

        $roh = (string) app('mailer')->getSymfonyTransport()->messages()[0]->toString();
        $klartext = quoted_printable_decode($roh);

        // Das Bild reist mit der Mail ...
        $this->assertStringContainsString('Content-ID:', $roh);
        $this->assertStringContainsString('image/svg+xml', $roh);
        $this->assertMatchesRegularExpression('#src="cid:#', $klartext);

        // ... und nicht ueber das Netz oder als data:-URI.
        $this->assertDoesNotMatchRegularExpression('#<img[^>]+src="(https?:)?//#', $klartext);
        $this->assertStringNotContainsString('src="data:', $klartext);
        $this->assertStringNotContainsString('fonts.googleapis', $klartext);
        $this->assertStringNotContainsString('@font-face', $klartext);
    }

    #[Test]
    public function without_brand_context_the_documents_stay_readable(): void
    {
        // Das Paket wird auch einzeln verkauft. Ohne brand-context gibt es
        // Vorgaben — neutral, nicht die Farben von adriangoldner.dev: wer
        // dieses Addon allein kauft, soll keine fremde Marke in seiner Rechnung
        // finden.
        $vorgabe = MarkenBild::vorgabe();

        $this->assertSame('', $vorgabe['name'], 'Ohne Marke keine fremde Wortmarke.');
        $this->assertNull($vorgabe['logo']);
        $this->assertSame('#ffffff', $vorgabe['paper']);
        $this->assertStringNotContainsString('http', $vorgabe['font']);
    }

    #[Test]
    public function an_unusable_brand_does_not_stop_an_invoice(): void
    {
        // Die haeufigste Fassung von „brand-context ist da, geht aber nicht":
        // Klassen installiert, Migrationen nie gelaufen. Eine Rechnung ist ein
        // Pflichtdokument — ein fehlendes Logo ist kein Grund, sie nicht
        // auszustellen.
        Schema::dropIfExists('brands');

        $marke = MarkenBild::fuer(7);

        $this->assertSame(MarkenBild::vorgabe(), $marke);
        $this->assertStringContainsString('RE2026-09-001', app(Renderer::class)->html($this->rechnung()->load('items')));
    }
}
