<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Die Rechnungsmail als Vorlage in statamic-email-templates.
 *
 * Befund aus ChoirLive (25.09.2026): jede andere Mail der Suite lässt sich im
 * CP umschreiben, die Rechnungsmail nicht. Sie meldet sich jetzt in der
 * Vorlagen-Registry an (`invoices-invoice`), und ein Eintrag unter diesem Slug
 * schreibt Betreff und Text. Die PDF hängt in jedem Fall an.
 *
 * Ohne Eintrag bleibt die Mail, wie sie war: die eingebaute Ansicht mit Logo
 * und Betragskasten. Eine Vorlage, die noch niemand angelegt hat, darf die
 * Mail, die ein Käufer zehn Jahre aufhebt, nicht verändern.
 */
class TheInvoiceMailIsATemplateTest extends TestCase
{
    protected string $content;

    protected function getPackageProviders($app): array
    {
        return [
            EmailTemplatesServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->getProvider(ServiceProvider::class)?->bootEvents();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->content);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Einträge landen in einem Verzeichnis dieses Prozesses, nicht in den
        // Fixtures von Testbench: sonst beginnt der nächste Lauf mit der
        // Vorlage, die dieser angelegt hat.
        $this->content = sys_get_temp_dir().'/invoices-et-'.getmypid().'-'.bin2hex(random_bytes(3));
        $app['config']->set('statamic.stache.stores.collections.directory', $this->content.'/collections');
        $app['config']->set('statamic.stache.stores.entries.directory', $this->content.'/collections');

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

    #[Test]
    public function the_mail_is_announced_to_the_registry_with_occasion_and_placeholders(): void
    {
        $registry = app('email-templates.registry');

        $this->assertTrue($registry->has('invoices-invoice'));

        $definition = $registry->find('invoices-invoice');
        $this->assertSame('Invoices', $definition->addon());
        $this->assertSame(InvoiceIssued::class, $definition->event);
        $this->assertNotSame('', $definition->trigger());

        $this->assertSame(
            ['buyer.name', 'buyer.email', 'invoice.number', 'invoice.date', 'amount', 'seller.name', 'site_name'],
            array_keys($definition->placeholders()),
        );

        $defaults = $definition->defaults();
        $this->assertStringContainsString('{{ invoice.number }}', $defaults['subject']);
        $this->assertStringContainsString('{{ amount }}', $defaults['body']);
    }

    #[Test]
    public function an_entry_writes_subject_and_text_and_the_pdf_still_travels(): void
    {
        $this->vorlage([
            'subject' => 'Deine Rechnung {{ invoice.number }}',
            'body' => '<p>Hallo {{ buyer.name }},</p><p>hier ist deine Rechnung über {{ amount }}.</p><p>{{ seller.name }}</p>',
        ]);

        PaymentPaid::dispatch($this->zahlung());

        $rechnung = Invoice::firstOrFail();
        $mail = $this->einzigeMail();

        $this->assertSame('Deine Rechnung '.$rechnung->number, $mail->getSubject());

        $html = (string) $mail->getHtmlBody();
        $this->assertStringContainsString('Hallo Bärbel Öztürk-Weiß,', html_entity_decode($html));
        $this->assertStringContainsString('119,00', $html);
        $this->assertStringContainsString('Nordlicht Studio', $html);
        $this->assertStringNotContainsString('im Anhang finden Sie', $html);

        $this->assertSame(['Rechnung-'.$rechnung->number.'.pdf'], $this->pdfs($mail));
    }

    #[Test]
    public function without_an_entry_the_built_in_mail_goes_out_unchanged(): void
    {
        PaymentPaid::dispatch($this->zahlung());

        $rechnung = Invoice::firstOrFail();
        $mail = $this->einzigeMail();

        $this->assertSame('Ihre Rechnung '.$rechnung->number, $mail->getSubject());
        $this->assertStringContainsString('im Anhang finden Sie Ihre Rechnung als PDF.', (string) $mail->getHtmlBody());
        $this->assertSame(['Rechnung-'.$rechnung->number.'.pdf'], $this->pdfs($mail));
    }

    #[Test]
    public function an_empty_slug_turns_the_template_off(): void
    {
        config(['invoices.delivery.template' => null]);

        $this->vorlage(['subject' => 'Deine Rechnung {{ invoice.number }}', 'body' => '<p>Hallo</p>']);

        PaymentPaid::dispatch($this->zahlung());

        $this->assertStringStartsWith('Ihre Rechnung ', (string) $this->einzigeMail()->getSubject());
    }

    /**
     * @param  array{subject: string, body: string}  $werte
     */
    protected function vorlage(array $werte): void
    {
        $manager = app(EmailTemplateCollectionManager::class);
        $manager->ensure();
        $manager->upsert(EmailTemplateData::fromArray($werte + ['slug' => 'invoices-invoice', 'title' => 'Rechnung']));
    }

    private function zahlung(): Payment
    {
        return Payment::create([
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
        ]);
    }

    private function einzigeMail(): Email
    {
        $post = Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->all();

        $this->assertCount(1, $post, 'die Rechnung hat den Käufer nicht erreicht');

        return $post[0];
    }

    /** @return list<string|null> */
    private function pdfs(Email $mail): array
    {
        return array_values(array_map(
            fn (DataPart $teil) => $teil->getFilename(),
            array_filter($mail->getAttachments(), fn (DataPart $teil) => $teil->getMediaSubtype() === 'pdf'),
        ));
    }
}
