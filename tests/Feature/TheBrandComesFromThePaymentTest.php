<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\IdentityContracts\ServiceProvider as IdentityProvider;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Goldnead\Invoices\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The invoice belongs to the brand that sold, not to the one that happens to be
 * current while it is written.
 *
 * Every case here goes through `PaymentPaid` **with no brand set in the
 * process**, because that is the only state that matters: a provider's webhook,
 * a console run and a follow-up charge all arrive without one, and those are
 * the three places invoices are actually written. A test that set a brand first
 * would pass against the defect it is supposed to forbid — `currentId()` falls
 * back to the default brand rather than to nothing, so brand B's invoice took
 * brand A's number series and brand A's sender, once, permanently, and without
 * a line anywhere saying it had happened.
 */
class TheBrandComesFromThePaymentTest extends TestCase
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

        if (! class_exists(\Goldnead\BrandContext\ServiceProvider::class)) {
            $this->markTestSkipped('brand-context is what this file is about.');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-brand-context/database/migrations');
        $this->artisan('migrate')->run();

        // Testbench feuert die Statamic-Boot-Callbacks nicht, also haengt der
        // Listener sonst an nichts — und der Weg ueber das Ereignis ist genau
        // der, um den es hier geht.
        $this->app->getProvider(ServiceProvider::class)?->bootEvents();

        config([
            'brand-context.multi_brand' => true,
            'invoices.number.prefix_per_brand' => [1 => 'AA', 2 => 'BB'],
            'invoices.seller_per_brand' => [
                1 => ['name' => 'Marke Eins', 'address' => 'Einsweg 1'],
                2 => ['name' => 'Marke Zwei', 'address' => 'Zweiweg 2'],
            ],
        ]);

        $this->marke(1, 'eins');
        $this->marke(2, 'zwei');

        app('brand-context')->forget();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Der Versand hat eine eigene Datei. Hier steht nur die Frage, in
        // welcher Reihe die Rechnung landet.
        $app['config']->set('invoices.delivery.enabled', false);

        $app['config']->set('invoices.tax', [
            'merchant_country' => 'DE',
            'prices_include_tax' => true,
            'default_product_class' => 'standard',
            'product_classes' => ['kurs' => 'standard'],
            'zones' => [['countries' => ['DE'], 'rates' => ['standard' => 1900]]],
        ]);
    }

    /**
     * Die Spalte, die statamic-payments seit 1.13 mitbringt.
     *
     * Wortgleich zur dortigen Migration (`unsignedBigInteger`, Default `0`,
     * Index) und nicht als bequemere Attrappe: eine Spalte, die hier `nullable`
     * waere, wuerde einen Fall pruefen, den es auf keiner Installation gibt.
     * Sie wird pro Test gelegt statt im Setup, weil die Abwesenheit der Spalte
     * unten ein eigener Fall ist — composer verlangt `^1.9`, und aeltere
     * Installationen duerfen nicht in einen SQL-Fehler laufen.
     */
    private function zahlungenTragenEineMarke(): void
    {
        if (Schema::hasColumn('payments', 'brand_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $tabelle) {
            $tabelle->unsignedBigInteger('brand_id')->default(0)->index();
        });
    }

    /** Der Stand vor jener Migration, den eine Installation heute noch haben darf. */
    private function zahlungenTragenKeineMarke(): void
    {
        if (! Schema::hasColumn('payments', 'brand_id')) {
            return;
        }

        // Erst der Index: SQLite weigert sich, eine Spalte zu loeschen, auf die
        // noch einer zeigt. Dieselbe Reihenfolge wie im `down()` drueben.
        Schema::table('payments', fn (Blueprint $tabelle) => $tabelle->dropIndex('payments_brand_id_index'));
        Schema::table('payments', fn (Blueprint $tabelle) => $tabelle->dropColumn('brand_id'));
    }

    /**
     * brand-context legt beim Migrieren schon eine Standardmarke an, deshalb
     * updateOrCreate: die erste Marke ist die, die es bereits gibt.
     */
    private function marke(int $id, string $handle): Brand
    {
        return Brand::updateOrCreate(['id' => $id], [
            'handle' => $handle,
            'name' => 'Marke '.$handle,
            'is_default' => $id === 1,
            'settings' => [],
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

    #[Test]
    public function an_invoice_written_with_no_brand_current_belongs_to_the_payments_brand(): void
    {
        $this->zahlungenTragenEineMarke();

        // Marke Zwei hat verkauft. Der Prozess, der gleich die Rechnung
        // schreibt, weiss davon nichts — wie jeder Webhook.
        $zahlung = $this->zahlung(['brand_id' => 2]);

        $this->assertFalse(app('brand-context')->hasCurrent(), 'der Test prüft den Zustand ohne Markenkontext');

        PaymentPaid::dispatch($zahlung);

        $rechnung = Invoice::firstOrFail();

        $this->assertSame(2, (int) $rechnung->brand_id, 'die Rechnung gehört der Standardmarke statt der, die verkauft hat');
        $this->assertStringStartsWith('BB', $rechnung->number, 'die Nummer kam aus der fremden Reihe');

        // Und damit auch der Absender: er wird aus derselben Marke eingefroren.
        $this->assertSame('Marke Zwei', $rechnung->seller['name']);
    }

    #[Test]
    public function two_brands_selling_in_one_run_keep_their_own_series(): void
    {
        $this->zahlungenTragenEineMarke();

        PaymentPaid::dispatch($this->zahlung(['brand_id' => 2]));
        PaymentPaid::dispatch($this->zahlung(['brand_id' => 1]));
        PaymentPaid::dispatch($this->zahlung(['brand_id' => 2]));

        $this->assertSame(
            ['BB', 'AA', 'BB'],
            Invoice::query()->orderBy('id')->pluck('number')->map(fn ($nr) => substr($nr, 0, 2))->all(),
        );

        $this->assertSame(
            ['001', '002'],
            Invoice::query()->where('brand_id', 2)->orderBy('id')->pluck('number')
                ->map(fn ($nr) => substr($nr, -3))->all(),
            'jede Marke zählt für sich — die zweite Rechnung von Marke Zwei ist deren zweite, nicht die dritte des Laufs',
        );
    }

    #[Test]
    public function a_payment_that_carries_no_brand_still_gets_its_invoice(): void
    {
        $this->zahlungenTragenEineMarke();

        Log::spy();

        // `brand_id = 0` im Mehrmarkenbetrieb: eine Zeile, die keiner Marke
        // gehoert — Altbestand ohne Backfill oder ein Checkout, waehrend
        // brand-context nicht sagen konnte, wer gerade dran ist. Sie bekommt
        // ihre Rechnung, denn wer bezahlt hat, hat Anspruch auf den Beleg, und
        // ein spaeterer Lauf koennte ihn nicht nachholen, ohne eine Luecke in
        // einer lueckenlosen Reihe zu hinterlassen.
        PaymentPaid::dispatch($this->zahlung(['brand_id' => 0]));

        $rechnung = Invoice::firstOrFail();

        $this->assertSame(1, (int) $rechnung->brand_id, 'ohne eigene Marke bleibt es beim bisherigen Verhalten');
        $this->assertStringStartsWith('AA', $rechnung->number);

        // Aber nicht stumm: das ist der einzige Fall, in dem die Marke noch
        // geraten wird, und er muss auffindbar sein.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($nachricht, $kontext = []) => is_string($nachricht)
                && str_contains($nachricht, 'carries no brand')
                && ($kontext['brand_id'] ?? null) === 1)
            ->once();
    }

    #[Test]
    public function a_single_brand_installation_writes_zero_whatever_the_payment_says(): void
    {
        $this->zahlungenTragenEineMarke();

        // Kein Mehrmarkenbetrieb: jede Zaehlerzeile, jeder Index und jede
        // bestehende Rechnung dieser Installation stehen auf 0. Eine Marke von
        // der Zahlung zu uebernehmen wuerde hier eine zweite Reihe eroeffnen,
        // die niemand bestellt hat.
        config(['brand-context.multi_brand' => false]);

        PaymentPaid::dispatch($this->zahlung(['brand_id' => 7]));

        $rechnung = Invoice::firstOrFail();

        $this->assertSame(0, (int) $rechnung->brand_id);
        $this->assertStringStartsWith('RE', $rechnung->number);
    }

    #[Test]
    public function an_installation_whose_payments_have_no_brand_column_still_writes_invoices(): void
    {
        $this->zahlungenTragenKeineMarke();

        // composer verlangt statamic-payments `^1.9`, die Spalte kam mit 1.13.
        // Dazwischen darf nichts nach ihr fragen: kein Schema-Blick, kein
        // SELECT auf eine Spalte, die es nicht gibt, kein SQL-Fehler auf einer
        // bezahlten Bestellung.
        PaymentPaid::dispatch($this->zahlung());

        $rechnung = Invoice::firstOrFail();

        $this->assertSame(1, (int) $rechnung->brand_id, 'ohne Spalte bleibt es beim bisherigen Verhalten');
        $this->assertStringStartsWith('AA', $rechnung->number);
    }
}
