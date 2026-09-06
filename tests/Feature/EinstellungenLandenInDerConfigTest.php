<?php

namespace Goldnead\Invoices\Tests\Feature;

use Goldnead\BrandContext\ServiceProvider as BrandContextServiceProvider;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Invoices\Support\NumberSeries;
use Goldnead\Invoices\Support\TaxResult;
use Goldnead\Invoices\Support\TaxRules;
use Goldnead\Invoices\Tests\TestCase;
use ReflectionClass;

/**
 * Der eine Beleg, den diese Umstellung schuldet: **ein gespeicherter Wert
 * landet wirklich in `config()`**.
 *
 * Alles andere an dieser Einstellungs-Seite gehört `statamic-brand-context` und
 * ist dort geprüft — Formular, Validierung, Rechte, Markendimension. Was hier
 * geprüft wird, überschreitet die Paketgrenze absichtlich: dass **dieses**
 * Addon seine Felder anmeldet, und dass der Weg von einer gespeicherten
 * Abweichung bis zu der Stelle trägt, an der {@see TaxRules}
 * und {@see NumberSeries} lesen. Ein Test, der nur
 * die Feldliste anschaut, belegt eine Liste; er belegt nicht, dass Speichern
 * wirkt.
 */
class EinstellungenLandenInDerConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(
            dirname((new ReflectionClass(BrandContextServiceProvider::class))->getFileName(), 2).'/database/migrations'
        );

        $this->artisan('migrate')->run();
    }

    protected function getPackageProviders($app): array
    {
        return array_merge([BrandContextServiceProvider::class], parent::getPackageProviders($app));
    }

    /** Der gespeicherte Wert steht danach dort, wo das Addon liest. */
    public function test_ein_gespeicherter_wert_landet_in_der_config(): void
    {
        $this->assertTrue(
            app(SettingsRegistry::class)->has('invoices'),
            'Das Addon hat seine Einstellungen nicht bei der SettingsRegistry angemeldet.'
        );

        $manager = app(SettingsManager::class);

        $manager->for('invoices')->save([
            'seller.name' => 'Goldner Chorwerk',
            // Mehrzeilig, und genau dafür gibt es den Typ `text`: die Vorlage
            // setzt den Absender mit `white-space: pre-line`.
            'seller.address' => "Beispielweg 1\n60311 Frankfurt",
            'number.prefix' => 'CW',
            'number.pad' => 5,
            'tax.small_business.enabled' => true,
        ]);

        // Erzwungen, weil die Schicht die Config im selben Prozess schon einmal
        // gesetzt hat und sonst mit „ist bereits richtig" früh aussteigt.
        $manager->apply(force: true);

        $this->assertSame('Goldner Chorwerk', config('invoices.seller.name'));
        $this->assertSame("Beispielweg 1\n60311 Frankfurt", config('invoices.seller.address'));
        $this->assertSame('CW', config('invoices.number.prefix'));
        $this->assertSame(5, config('invoices.number.pad'));
        $this->assertTrue(config('invoices.tax.small_business.enabled'));
    }

    /**
     * Die Preisbasis steht **nicht** auf der Seite, und das ist Absicht.
     *
     * `tax.prices_include_tax` hat drei Zustände: brutto, netto und „noch
     * niemand hat geantwortet". Der dritte ist die Paketvorgabe, und er ist
     * etwas anderes als netto — {@see TaxRules} reicht null durch und
     * {@see TaxResult::split()} verweigert dann die Aufteilung, statt still zu
     * rechnen. Die Feldtypen der Schicht tragen zwei Zustände. Ein `boolean`
     * hätte den dritten beim ersten Speichern lautlos in „netto" verwandelt,
     * und zwar auf jeder Rechnung; ein nullable `select` scheiterte an der
     * anderen Seite (in `config` steht auf eingerichteten Installationen ein
     * `bool`, und die `Select`-Komponente der Schicht nimmt keinen booleschen
     * Wert an — die ganze Seite blieb leer, für alle Addons).
     *
     * Der Test hält das fest, damit der nächste Versuch, das Feld
     * „nachzutragen", hier anschlägt und nicht auf einer Kundeninstallation.
     */
    public function test_die_preisbasis_steht_nicht_auf_der_seite(): void
    {
        $this->assertArrayNotHasKey(
            'tax.prices_include_tax',
            app(SettingsRegistry::class)->fields('invoices')
        );
    }

    /**
     * Kein Feld dieses Addons trägt ein Geheimnis, und keins wird beim Booten
     * gelesen.
     *
     * Die zweite Hälfte ist die Falle, an der diese Umstellung sonst scheitert:
     * `SettingsManager::apply()` läuft aus `app->booted()`, also sieht alles,
     * was während des Bootens liest — Routen, Nav, Schedule — noch den
     * Paketwert. Ein Schlüssel, der in `routes/web.php` gelesen wird, gehört
     * nicht auf die Seite, weil die Änderung dort nie ankommt. Dieses Addon
     * liest in seinen Routen keine Konfiguration; der Test hält das fest,
     * damit die erste Route, die es doch tut, hier auffällt und nicht auf einer
     * Kundeninstallation.
     */
    public function test_keine_route_liest_einen_schluessel_dieser_seite(): void
    {
        $routen = file_get_contents(__DIR__.'/../../routes/web.php');

        foreach (array_keys(app(SettingsRegistry::class)->fields('invoices')) as $key) {
            $this->assertStringNotContainsString(
                'invoices.'.$key,
                $routen,
                "[{$key}] wird beim Registrieren der Routen gelesen und darf deshalb nicht auf der Einstellungs-Seite stehen."
            );
        }
    }
}
