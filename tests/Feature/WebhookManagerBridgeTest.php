<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\Invoices\Events\InvoiceDelivered;
use Goldnead\Invoices\Integrations\WebhookManager\InvoicesTrigger;
use Goldnead\Invoices\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Invoices\Integrations\WebhookManager\WebhookPayload;
use Goldnead\Invoices\InvoiceWriter;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\Invoices\Tests\Unit\BootWithoutWebhookManagerTest;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/**
 * The invoice moments as triggers of the real webhook manager, booted with its
 * own migrations and dispatch pipeline. The install without it is proved in a
 * separate process, {@see BootWithoutWebhookManagerTest}.
 */
class WebhookManagerBridgeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        array_splice($providers, 1, 0, [BrandContextServiceProvider::class, WebhookManagerServiceProvider::class]);

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('invoices.delivery.enabled', false);
        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([BrandContextServiceProvider::class, WebhookManagerServiceProvider::class] as $provider) {
            $this->loadMigrationsFrom(dirname((new ReflectionClass($provider))->getFileName(), 2).'/database/migrations');
        }
        $this->artisan('migrate')->run();

        // This suite boots Statamic, which finds the manager in the addon
        // manifest and runs its bootAddon(), and the bridge registers from its
        // booted callback as in production. Only where that did not happen are
        // the manager's registries and TriggerDetected listener wired by hand;
        // doing it twice would rebind the registry and drop the triggers.
        if (! $this->app->bound('webhook-manager')) {
            $manager = $this->app->getProvider(WebhookManagerServiceProvider::class);
            foreach (['bootWebhookConfig', 'bootBindings', 'bootRegistries', 'bootEvents'] as $method) {
                (new ReflectionMethod($manager, $method))->invoke($manager);
            }
        }

        $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));

        WebhookPayload::forgetBrands();
        Carbon::setTestNow('2026-09-24 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function the_three_moments_are_triggers_labelled_in_the_viewers_language(): void
    {
        $registered = array_values(array_filter(
            array_keys(WebhookManager::triggers()->all()),
            fn (string $handle) => str_starts_with($handle, 'invoices.'),
        ));
        sort($registered);

        $this->assertSame(['invoices.credit_note_issued', 'invoices.delivered', 'invoices.issued'], $registered);
        $this->assertInstanceOf(InvoicesTrigger::class, WebhookManager::triggers()->get('invoices.issued'));

        $this->app->setLocale('de');
        $this->assertSame('Rechnungen: Rechnung ausgestellt', WebhookManager::triggers()->options()['invoices.issued']);
        $this->app->setLocale('en');
        $this->assertSame('Invoices: invoice issued', WebhookManager::triggers()->options()['invoices.issued']);
    }

    #[Test]
    public function an_issued_invoice_carries_number_amounts_and_buyer_and_nothing_else(): void
    {
        Event::fake([TriggerDetected::class]);

        $rechnung = app(InvoiceWriter::class)->forPayment($this->bezahlt());

        $detected = $this->detected('invoices.issued');
        $body = $detected->trigger->payload;

        $this->assertSame('invoices', $detected->trigger->sourceType);
        $this->assertSame((string) $rechnung->id, $detected->trigger->sourceReference);
        $this->assertSame(['event', 'occurred_at', 'brand', 'subject_type', 'subject_id', 'invoice'], array_keys($body));
        $this->assertSame('2026-09-24T10:00:00+00:00', $body['occurred_at']);
        $this->assertSame(['invoice', $rechnung->id], [$body['subject_type'], $body['subject_id']]);
        $this->assertSame([
            'id', 'number', 'kind', 'payment_id', 'reverses_invoice_id', 'issued_at', 'currency',
            'net_cent', 'tax_cent', 'gross_cent', 'tax_zone', 'buyer_name', 'buyer_email',
            'buyer_country', 'buyer_vat_id', 'items',
        ], array_keys($body['invoice']));
        $this->assertSame($rechnung->number, $body['invoice']['number']);
        $this->assertSame(11900, $body['invoice']['gross_cent']);
        $this->assertSame(10000, $body['invoice']['net_cent']);
        $this->assertSame('EUR', $body['invoice']['currency']);
        $this->assertSame('wer@example.com', $body['invoice']['buyer_email']);
        $this->assertCount(1, $body['invoice']['items']);
        $this->assertSame(1900, $body['invoice']['items'][0]['tax_rate_bp']);

        $json = json_encode($body);
        foreach (['Musterweg', 'seller', 'meta', 'vat_id_reference'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "The body carries [{$secret}].");
        }
    }

    #[Test]
    public function a_credit_note_names_what_it_reverses_and_reaches_an_outbound_hook(): void
    {
        Queue::fake();
        $this->hook('invoices.credit_note_issued');

        $zahlung = $this->bezahlt();
        $original = app(InvoiceWriter::class)->forPayment($zahlung);

        Event::listen(TriggerDetected::class, function (TriggerDetected $detected) use (&$body) {
            if ($detected->trigger->triggerHandle === 'invoices.credit_note_issued') {
                $body = $detected->trigger->payload;
            }
        });

        $storno = app(InvoiceWriter::class)->creditNoteFor($zahlung->fresh());

        $this->assertSame(['id' => $original->id, 'number' => $original->number], $body['reverses']);
        $this->assertSame('credit_note', $body['credit_note']['kind']);
        $this->assertSame(['invoice', $storno->id], [$body['subject_type'], $body['subject_id']]);

        Queue::assertPushed(ProcessOutboundDeliveryJob::class);
        $delivery = DB::table('webhook_deliveries')->where('trigger_type', 'invoices.credit_note_issued')->first();
        $this->assertNotNull($delivery);
        $this->assertSame(['invoice', (string) $storno->id], [$delivery->subject_type, $delivery->subject_id]);
        $this->assertStringContainsString($original->number, (string) $delivery->request_body);
    }

    #[Test]
    public function a_delivery_says_where_it_went(): void
    {
        Event::fake([TriggerDetected::class]);

        $rechnung = app(InvoiceWriter::class)->forPayment($this->bezahlt());
        InvoiceDelivered::dispatch($rechnung, 'wer@example.com');

        $body = $this->detected('invoices.delivered')->trigger->payload;
        $this->assertSame('wer@example.com', $body['to']);
        $this->assertSame($rechnung->number, $body['invoice']['number']);
    }

    #[Test]
    public function an_invoice_of_another_brand_goes_through_that_brands_hook_only(): void
    {
        config(['brand-context.multi_brand' => true]);
        Queue::fake();

        $zweite = (int) DB::table('brands')->insertGetId([
            'handle' => 'zweite', 'name' => 'Zweite', 'is_default' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $default = (int) DB::table('brands')->where('is_default', true)->value('id');
        config(['invoices.number.prefix_per_brand' => [$default => 'NL', $zweite => 'ZW']]);

        app('brand-context')->runFor($default, fn () => $this->hook('invoices.issued', 'default-hook'));
        app('brand-context')->runFor($zweite, fn () => $this->hook('invoices.issued', 'zweite-hook'));
        app('brand-context')->forget();

        Event::listen(TriggerDetected::class, function (TriggerDetected $detected) use (&$body) {
            $body = $detected->trigger->payload;
        });

        // A payment webhook: no brand is current, the payment names one.
        app(InvoiceWriter::class)->forPayment($this->bezahlt(['brand_id' => $zweite]));

        $this->assertSame(['id' => $zweite, 'handle' => 'zweite'], $body['brand']);
        $hooks = DB::table('webhook_deliveries')
            ->join('webhook_outbounds', 'webhook_outbounds.id', '=', 'webhook_deliveries.outbound_webhook_id')
            ->pluck('webhook_outbounds.handle')->all();
        $this->assertSame(['zweite-hook'], $hooks);
        $this->assertFalse(app('brand-context')->hasCurrent());
    }

    #[Test]
    public function a_failing_manager_never_costs_the_invoice(): void
    {
        Event::listen(TriggerDetected::class, function () {
            throw new \RuntimeException('manager down');
        });

        $rechnung = app(InvoiceWriter::class)->forPayment($this->bezahlt());

        $this->assertNotNull($rechnung->number);
    }

    #[Test]
    public function a_second_boot_adds_no_second_listener_and_the_switch_turns_it_off(): void
    {
        $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));

        $heard = [];
        Event::listen(TriggerDetected::class, function (TriggerDetected $d) use (&$heard) {
            $heard[] = $d->trigger->triggerHandle;
        });

        app(InvoiceWriter::class)->forPayment($this->bezahlt());
        $this->assertSame(['invoices.issued'], $heard);

        config(['invoices.webhook_manager.enabled' => false]);
        $this->assertFalse(WebhookManagerBridge::available());
    }

    private function detected(string $handle): TriggerDetected
    {
        $found = collect(Event::dispatched(TriggerDetected::class))
            ->map(fn ($call) => $call[0])
            ->first(fn (TriggerDetected $d) => $d->trigger->triggerHandle === $handle);

        $this->assertNotNull($found, "No TriggerDetected for [{$handle}].");

        return $found;
    }

    private function hook(string $trigger, string $handle = 'hook'): OutboundWebhook
    {
        return OutboundWebhook::create([
            'uuid' => (string) Str::uuid(), 'name' => $handle, 'handle' => $handle, 'enabled' => true,
            'trigger_type' => $trigger, 'url' => 'https://example.test/hook', 'method' => 'POST',
            'payload_type' => 'raw_json', 'queue_enabled' => true,
        ]);
    }

    private function bezahlt(array $werte = []): Payment
    {
        return Payment::create(array_merge([
            'provider' => 'fake', 'provider_id' => 'tr_'.Str::random(6), 'product' => 'kurs',
            'amount_cent' => 11900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID,
            'email' => 'wer@example.com', 'name' => 'Bärbel Öztürk-Weiß',
            'country' => 'DE', 'paid_at' => now(),
            'meta' => ['address' => 'Musterweg 1, 12345 Musterstadt'],
        ], $werte));
    }
}
