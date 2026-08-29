<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\IdentityContracts\ServiceProvider as IdentityProvider;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;

/**
 * Whose name is on the envelope.
 *
 * An invoice names the seller as a matter of law and the buyer keeps it for ten
 * years. If it arrives from a different brand's address, the envelope
 * contradicts the document inside it — and the document is the one that cannot
 * be corrected afterwards, only reversed and reissued.
 *
 * The dangerous case is not the brand that got its settings wrong. It is the
 * brand that filled in nothing at all: on a multi-brand install the host-wide
 * from-address belongs to *one* of the brands, so "unchanged" silently means
 * "under the neighbour's name". Every case below is about not letting that
 * happen quietly.
 */
class TheSenderIsTheBrandsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), array_values(array_filter([
            class_exists(IdentityProvider::class) ? IdentityProvider::class : null,
            class_exists(\Goldnead\BrandContext\ServiceProvider::class) ? \Goldnead\BrandContext\ServiceProvider::class : null,
        ])));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->artisan('migrate')->run();

        $this->app->getProvider(ServiceProvider::class)?->bootEvents();

        config([
            'brand-context.multi_brand' => true,
            'invoices.number.prefix_per_brand' => [1 => 'AA', 2 => 'BB'],
            // Jede Marke mit eigenen Absenderangaben. Genau die frieren beim
            // Schreiben auf der Rechnung ein — und stehen damit auch dann noch
            // richtig auf dem Dokument, wenn niemand `settings.mail` gepflegt hat.
            'invoices.seller_per_brand' => [
                1 => ['name' => 'Marke Eins', 'address' => 'Einsweg 1', 'email' => 'post@marke-eins.test'],
                2 => ['name' => 'Marke Zwei', 'address' => 'Zweiweg 2', 'email' => 'post@marke-zwei.test'],
            ],
        ]);

        app('brand-context')->forget();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'post@marke-eins.test', 'name' => 'Marke Eins']);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    /**
     * brand-context legt beim Migrieren bereits eine Standardmarke an, deshalb
     * updateOrCreate: die erste Marke ist die, die schon da ist.
     */
    private function marke(int $id, string $handle, array $settings = []): Brand
    {
        return Brand::updateOrCreate(['id' => $id], [
            'handle' => $handle,
            'name' => 'Marke '.($id === 1 ? 'Eins' : 'Zwei'),
            'is_default' => $id === 1,
            'settings' => $settings,
        ]);
    }

    private function bezahltFuer(Brand $marke): void
    {
        app('brand-context')->setCurrent($marke);

        PaymentPaid::dispatch(Payment::create([
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
        ]));
    }

    /** @return list<Email> */
    private function postausgang(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->all();
    }

    #[Test]
    public function a_brand_with_no_sender_details_does_not_borrow_the_neighbours(): void
    {
        // Marke Zwei hat `settings.mail` nie ausgefuellt. Der Rueckfall waere
        // `config('mail.from')` — und das ist hier buchstaeblich die Adresse von
        // Marke Eins. Stattdessen zieht der auf der Rechnung eingefrorene
        // Verkaeufer, der zu diesem Dokument gehoert.
        $this->marke(1, 'eins');
        $zwei = $this->marke(2, 'zwei');

        $this->bezahltFuer($zwei);

        $rechnung = Invoice::firstOrFail();
        $this->assertSame(2, (int) $rechnung->brand_id);

        $post = $this->postausgang();
        $this->assertCount(1, $post);

        $von = $post[0]->getFrom()[0];

        $this->assertSame('post@marke-zwei.test', $von->getAddress(), 'die Rechnung ging unter fremdem Namen hinaus');
        $this->assertSame('Marke Zwei', $von->getName());
    }

    #[Test]
    public function a_brand_that_declares_its_identity_sends_under_it(): void
    {
        $this->marke(1, 'eins');
        $zwei = $this->marke(2, 'zwei', [
            'mail' => ['from_address' => 'rechnung@marke-zwei.test', 'from_name' => 'Marke Zwei Rechnungen'],
        ]);

        $this->bezahltFuer($zwei);

        $von = $this->postausgang()[0]->getFrom()[0];

        $this->assertSame('rechnung@marke-zwei.test', $von->getAddress());
        $this->assertSame('Marke Zwei Rechnungen', $von->getName());
    }

    #[Test]
    public function a_brand_that_declares_a_broken_identity_sends_nothing_at_all(): void
    {
        // Sie hat gesagt, sie habe eine Absenderidentitaet, und dann die Haelfte
        // weggelassen, die der Relay prueft. Der Rueckfall auf die host-weite
        // Adresse waere dieselbe Verwechslung wie oben, nur still — also geht
        // gar nichts hinaus.
        $this->marke(1, 'eins');
        $zwei = $this->marke(2, 'zwei', ['mail' => ['from_name' => 'Marke Zwei']]);

        $this->bezahltFuer($zwei);

        // Die Rechnung existiert trotzdem. Sie gehoert in die Buchhaltung, und
        // ein Postfachproblem ist kein Grund, ein Steuerdokument nicht zu haben.
        $this->assertSame(1, Invoice::count());
        $this->assertSame([], $this->postausgang(), 'es ging Post unter einer verweigerten Identität hinaus');
    }

    #[Test]
    public function each_brands_invoice_leaves_under_its_own_name_in_the_same_run(): void
    {
        // Der Fall, der die ganze Konstruktion erklaert: zwei Marken in einem
        // Prozess. Ein Absender, der aus der Konfiguration gelesen und dort
        // gesetzt wird, bleibt nach dem ersten Versand stehen — die zweite
        // Rechnung geht dann unter dem Namen der ersten hinaus.
        $eins = $this->marke(1, 'eins', ['mail' => ['from_address' => 'rechnung@marke-eins.test']]);
        $zwei = $this->marke(2, 'zwei', ['mail' => ['from_address' => 'rechnung@marke-zwei.test']]);

        $this->bezahltFuer($eins);
        $this->bezahltFuer($zwei);

        $adressen = array_map(fn ($mail) => $mail->getFrom()[0]->getAddress(), $this->postausgang());

        $this->assertSame(['rechnung@marke-eins.test', 'rechnung@marke-zwei.test'], $adressen);
    }
}
