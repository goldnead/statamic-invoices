<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
use Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager;
use Goldnead\EmailTemplates\Support\EmailTemplateData;
use Goldnead\Invoices\Integrations\EmailTemplates\InvoiceMailTemplate;
use Goldnead\Invoices\Integrations\EmailTemplates\TemplateSource;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Was adriangoldner.com an seiner eigenen Rechnungsmail hatte und dem Addon
 * fehlte (Vergleich 04.10.2026): der Produktname, ein Reply-To und eine
 * Vorlage, in der die Seite ihre eigene Ansprache pflegt.
 *
 * Die Mail geht hier über die echte Kette raus, von `PaymentPaid` bis zum
 * `array`-Transport, damit Betreff, Text, Kopfzeilen und Anhang so geprüft
 * werden, wie ein Postfach sie bekommt.
 */
class TheInvoiceMailSaysWhatWasBoughtTest extends TestCase
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

        $this->content = sys_get_temp_dir().'/invoices-bought-'.getmypid().'-'.bin2hex(random_bytes(3));
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
    public function a_template_entry_names_the_product_in_the_sites_own_voice(): void
    {
        $this->vorlage([
            'subject' => 'Deine Rechnung {{ invoice.number }}',
            'body' => '<p>Hallo {{ buyer.name }},</p><p>hier ist deine Rechnung {{ invoice.number }} zu <strong>{{ product }}</strong>.</p>',
        ]);

        PaymentPaid::dispatch($this->zahlung());

        $rechnung = Invoice::firstOrFail();
        $mail = $this->einzigeMail();

        $this->assertSame('Deine Rechnung '.$rechnung->number, $mail->getSubject());

        $html = html_entity_decode((string) $mail->getHtmlBody());
        $this->assertStringContainsString('Hallo Bärbel Öztürk-Weiß,', $html);
        $this->assertStringContainsString('zu <strong>Chorleitungskurs</strong>', $html);
        $this->assertSame(['Rechnung-'.$rechnung->number.'.pdf'], $this->pdfs($mail));
    }

    #[Test]
    public function the_product_placeholder_is_announced_to_the_registry(): void
    {
        $definition = app('email-templates.registry')->find('invoices-invoice');

        $this->assertArrayHasKey('product', $definition->placeholders());
        $this->assertStringContainsString('{{ product }}', $definition->defaults()['body']);
    }

    #[Test]
    public function an_email_templates_without_a_registry_gets_the_mail_as_an_import_source(): void
    {
        // email-templates before 2.8: the interface is there, the registry is not.
        // The registry is an alias of its class; the container has no public way
        // to drop one, so it is taken out by hand.
        $aliases = new \ReflectionProperty(Container::class, 'aliases');
        $alle = $aliases->getValue($this->app);
        unset($alle['email-templates.registry']);
        $aliases->setValue($this->app, $alle);
        $this->assertFalse($this->app->bound('email-templates.registry'));

        $this->assertTrue(app(InvoiceMailTemplate::class)->register());

        $quellen = array_filter(
            [...$this->app->tagged('email-templates.sources')],
            fn ($quelle) => $quelle instanceof TemplateSource,
        );
        $this->assertCount(1, $quellen);

        $vorlagen = array_values($quellen)[0]->all();
        $this->assertSame('invoices-invoice', $vorlagen[0]->slug);
        $this->assertStringContainsString('{{ product }}', $vorlagen[0]->body);
    }

    #[Test]
    public function without_an_entry_the_built_in_mail_keeps_its_text_and_names_the_product(): void
    {
        PaymentPaid::dispatch($this->zahlung());

        $rechnung = Invoice::firstOrFail();
        $mail = $this->einzigeMail();

        $this->assertSame('Ihre Rechnung '.$rechnung->number, $mail->getSubject());

        $html = (string) $mail->getHtmlBody();
        $this->assertStringContainsString('im Anhang finden Sie Ihre Rechnung als PDF.', $html);
        $this->assertStringContainsString('Chorleitungskurs', $html);
        $this->assertSame(['Rechnung-'.$rechnung->number.'.pdf'], $this->pdfs($mail));
    }

    #[Test]
    public function a_configured_reply_to_is_on_the_mail_with_or_without_a_template(): void
    {
        config([
            'invoices.delivery.reply_to' => 'fragen@nordlicht.test',
            'invoices.delivery.reply_to_name' => 'Nordlicht Fragen',
        ]);

        PaymentPaid::dispatch($this->zahlung());

        $antwort = $this->einzigeMail()->getReplyTo();
        $this->assertCount(1, $antwort);
        $this->assertSame('fragen@nordlicht.test', $antwort[0]->getAddress());
        $this->assertSame('Nordlicht Fragen', $antwort[0]->getName());

        Mail::mailer()->getSymfonyTransport()->flush();

        $this->vorlage(['subject' => 'Deine Rechnung {{ invoice.number }}', 'body' => '<p>Hallo</p>']);
        PaymentPaid::dispatch($this->zahlung());

        $this->assertSame('fragen@nordlicht.test', $this->einzigeMail()->getReplyTo()[0]->getAddress());
    }

    #[Test]
    public function an_empty_reply_to_sets_none(): void
    {
        config(['invoices.delivery.reply_to' => '']);

        PaymentPaid::dispatch($this->zahlung());

        $this->assertSame([], $this->einzigeMail()->getReplyTo());
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

        $this->assertCount(1, $post, 'die Rechnung hat den Käufer nicht genau einmal erreicht');

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
