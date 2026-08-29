<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\Invoices\Console\Commands\PendingInvoices;
use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Events\InvoiceDelivered;
use Goldnead\Invoices\Mail\InvoiceMail;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Support\DompdfRenderer;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * From a paid payment to a PDF in the buyer's mailbox, in one run.
 *
 * The point of this file is that it does not stop at the seam. The addon's own
 * listeners are wired the way `AddonServiceProvider` wires them in production —
 * discovered from `src/Listeners`, off the first parameter type — and the run
 * starts at `PaymentPaid`, which is `statamic-payments`' event, not this
 * addon's. Everything in between happens for real: the invoice is written, the
 * document is rendered, the mail is assembled and handed to a transport.
 *
 * The mail is not faked either. A `Mail::fake()` records that something was
 * passed to the mailer; it does not render the Blade view, does not run
 * `Mailable::build()` and therefore never finds out whether the attachment is a
 * PDF or whether the sender survived. The `array` transport does all of that
 * and keeps the finished MIME message, which is what an inbox would receive.
 *
 * Three shipped addons with three green suites once broke at exactly this kind
 * of boundary, each of them correct on its own side of it.
 */
class DeliveredToTheBuyerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The listener wiring itself, not a hand-registered stand-in for it. A
        // test that calls `Event::listen(...)` proves the listener works and
        // says nothing about whether it is ever reached — which is the half
        // that actually breaks.
        $this->app->getProvider(ServiceProvider::class)?->bootEvents();

        // The console command is discovered by the same provider in production;
        // under testbench nothing calls `bootCommands()`, so it is registered
        // here rather than left untested.
        $this->app[Kernel::class]->registerCommand($this->app->make(PendingInvoices::class));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'post@host.test', 'name' => 'Der Host']);

        $app['config']->set('invoices.seller', [
            'name' => 'Nordlicht Studio',
            'address' => "Beispielweg 1\n20095 Hamburg",
            'vat_id' => 'DE123456789',
            'email' => 'rechnung@nordlicht.test',
        ]);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    private function zahlung(array $werte = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake',
            'provider_id' => 'tr_'.bin2hex(random_bytes(4)),
            'product' => 'kurs',
            'amount_cent' => 11900,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PAID,
            'email' => 'baerbel@example.com',
            'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE',
            'paid_at' => now(),
        ], $werte));
    }

    /** @return list<Email> */
    private function postausgang(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->all();
    }

    #[Test]
    public function a_paid_payment_ends_as_a_pdf_in_the_buyers_mailbox(): void
    {
        PaymentPaid::dispatch($this->zahlung());

        $rechnung = Invoice::firstOrFail();
        $post = $this->postausgang();

        $this->assertCount(1, $post, 'die Rechnung hat den Käufer nicht erreicht');

        $mail = $post[0];

        $this->assertSame('baerbel@example.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('Bärbel Öztürk-Weiß', $mail->getTo()[0]->getName());
        $this->assertStringContainsString($rechnung->number, (string) $mail->getSubject());

        $anhaenge = array_values(array_filter(
            $mail->getAttachments(),
            fn (DataPart $teil) => $teil->getMediaSubtype() === 'pdf',
        ));

        $this->assertCount(1, $anhaenge, 'die Mail kam ohne PDF an');
        $this->assertSame('Rechnung-'.$rechnung->number.'.pdf', $anhaenge[0]->getFilename());

        // Und es ist dieselbe Datei, die ein zweiter Abruf liefern wuerde. Das
        // ist die Zusage aus zwei Richtungen: was zugestellt wurde, ist
        // reproduzierbar, und die Zustellung nimmt keinen Sonderweg an der
        // Erzeugung vorbei.
        $this->assertSame(
            md5(app(PdfRenderer::class)->render($rechnung)),
            md5($anhaenge[0]->getBody()),
        );
    }

    #[Test]
    public function the_delivery_is_announced_so_an_immutable_row_does_not_have_to_carry_it(): void
    {
        Event::fake([InvoiceDelivered::class]);

        PaymentPaid::dispatch($this->zahlung());

        Event::assertDispatched(
            InvoiceDelivered::class,
            fn (InvoiceDelivered $e) => $e->to === 'baerbel@example.com'
                && $e->invoice->number === Invoice::firstOrFail()->number,
        );
    }

    #[Test]
    public function an_incomplete_invoice_is_still_not_written_and_the_send_changes_nothing(): void
    {
        // Der Zustellweg darf keine Rechnung erzeugen, die es ohne ihn nicht
        // gaebe. Ueber 250 EUR verlangt § 14 UStG die Anschrift des Empfaengers,
        // und die fehlt hier — der Auslieferer haengt hinter dem Ereignis, das
        // dann gar nicht erst feuert.
        PaymentPaid::dispatch($this->zahlung(['amount_cent' => 30000]));

        $this->assertSame(0, Invoice::count(), 'die unvollständige Rechnung wurde geschrieben');
        $this->assertSame([], $this->postausgang(), 'es ging Post ohne Rechnung hinaus');
    }

    #[Test]
    public function the_pending_command_says_which_detail_is_missing(): void
    {
        // Und die fehlende Angabe steht danach in `invoices:pending`, statt den
        // Lauf mit einem Stacktrace abzubrechen.
        $this->zahlung(['amount_cent' => 30000]);

        $this->artisan('invoices:pending --write')
            ->expectsOutputToContain('recipient has no address')
            ->assertExitCode(0);

        $this->assertSame(0, Invoice::count());
        $this->assertSame([], $this->postausgang());
    }

    #[Test]
    public function a_buyer_without_an_address_gets_no_mail_and_keeps_the_invoice(): void
    {
        // Bezahlt, aber ohne Postfach. Die Rechnung existiert trotzdem — sie
        // gehoert in die Buchhaltung, nicht nur in ein Postfach.
        PaymentPaid::dispatch($this->zahlung(['email' => null]));

        $this->assertSame(1, Invoice::count());
        $this->assertSame([], $this->postausgang());
    }

    #[Test]
    public function a_host_that_sends_the_invoices_itself_turns_this_off(): void
    {
        config(['invoices.delivery.enabled' => false]);

        PaymentPaid::dispatch($this->zahlung());

        $this->assertSame(1, Invoice::count(), 'ohne Versand gibt es trotzdem eine Rechnung');
        $this->assertSame([], $this->postausgang());
    }

    #[Test]
    public function the_host_may_bind_its_own_engine_and_the_mail_carries_what_it_produced(): void
    {
        // Der Renderer haengt an einem Contract, nicht an einer festen Klasse.
        // Der Doppelgaenger hier gibt nicht irgendetwas zurueck: er reicht die
        // echte Erzeugung durch und zaehlt mit. Eine Attrappe, die "ok"
        // zurueckgibt, wuerde beweisen, dass jemand sie aufgerufen hat, und
        // nicht, dass eine PDF-Datei am Kaeufer ankommt.
        $spion = new class(app(DompdfRenderer::class)) implements PdfRenderer
        {
            public int $aufrufe = 0;

            public function __construct(private PdfRenderer $echt) {}

            public function render(Invoice $invoice): string
            {
                $this->aufrufe++;

                return $this->echt->render($invoice);
            }
        };

        $this->app->instance(PdfRenderer::class, $spion);

        PaymentPaid::dispatch($this->zahlung());

        $this->assertSame(1, $spion->aufrufe);
        $this->assertSame('%PDF-', substr((string) $this->postausgang()[0]->getAttachments()[0]->getBody(), 0, 5));
    }

    #[Test]
    public function the_mailable_refuses_to_be_queued(): void
    {
        // BrandMailer weist eine ShouldQueue-Mailable ab, weil sie erst nach
        // dem Versand gebaut wird — dann ist die Absenderidentitaet weg und
        // nichts wird rot. `InvoiceMail` traegt die Zusage deshalb gar nicht
        // erst.
        $this->assertNotInstanceOf(
            ShouldQueue::class,
            new InvoiceMail(new Invoice(['number' => 'RE1']), '%PDF-1.4', 'x.pdf'),
        );
    }
}
