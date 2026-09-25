<?php

namespace Goldnead\Invoices\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;
use Goldnead\BrandContext\Settings\SettingsRegistry;

/**
 * Die Einstellungen, die ein Betreiber im Control Panel ändern darf — und die
 * einzige Stelle, die weiß, welche das sind.
 *
 * **Nur die Feldliste steht hier.** Bildschirm, Formular, Validierung, Routen,
 * Speicher und die Markendimension kommen aus der gemeinsamen
 * Einstellungs-Schicht in `statamic-brand-context`; angemeldet wird diese
 * Klasse mit {@see SettingsRegistry} im Service Provider. Es gibt hier keinen
 * eigenen Controller, kein eigenes Formular, keine eigene Vue-Seite und
 * ausdrücklich kein `settingsBlueprint()` — das fasst `config()` nicht an und
 * speichert Vollkopien statt Abweichungen.
 *
 * **Abweichungen, keine Kopie.** Gespeichert wird nur, was jemand wirklich
 * geändert hat. Alles andere folgt weiter `config/invoices.php`, ein Update
 * des Pakets verschiebt also die Vorgaben, und eine Installation, die diesen
 * Bildschirm nie öffnet, verhält sich wie vor seiner Existenz.
 *
 * **Warum dieses Addon die Schicht am dringendsten braucht.** Die
 * Verkäuferidentität, der § 19-Schalter und die Steuerschalter standen bisher
 * ausschließlich in `.env`. Eine `.env` hat aber genau einen Wert je
 * Schlüssel, und auf einem Mehrmarken-Host haben zwei Marken zwei Anschriften,
 * zwei Steuernummern und womöglich zwei Antworten auf § 19. Das ist mit `.env`
 * nicht abbildbar, und was auf der Rechnung steht, ist keine Kleinigkeit: eine
 * Rechnung ohne die Angaben des leistenden Unternehmers ist nach § 14 UStG
 * keine Rechnung, und korrigieren lässt sich das nachträglich nicht — die
 * Angaben werden beim Ausstellen auf das Dokument eingefroren.
 *
 * **Was nicht hier steht, und warum.**
 *
 * - `tax.zones`, `tax.product_classes`, `tax.exemptions`,
 *   `number.prefix_per_brand`, `seller_per_brand`: verschachtelte Abbildungen.
 *   Der Vertrag kennt `string|text|integer|boolean|list|select`, und ein Typ,
 *   der alle vier Formen trüge, wäre ein Formular-Baukasten. Sie bleiben in
 *   `config/invoices.php` und werden auf dem Bildschirm benannt statt
 *   verschwiegen (Entscheidung 06.09.2026). Die beiden `*_per_brand`-Blöcke
 *   erledigen sich hier ohnehin: die Schicht ist markenfähig, jede Marke setzt
 *   `seller.*` und `number.prefix` für sich.
 * - `number.period` und `number.separator`: `period` ist ein Datumsformat, bei
 *   dem „nicht gesetzt" (folgt `config`, also monatlich) und „auf leer gesetzt"
 *   (Serie startet nie neu) im Formular dasselbe leere Feld sind — zwei
 *   Verhalten hinter einer Anzeige. `separator` ist derselbe Fall in klein und
 *   wird einmal beim Einrichten entschieden, nicht im Betrieb.
 * - `tax.vat_id_check.service`: der Name einer Bindung
 *   (`Contracts\VatIdVerifier`), nicht ein Betreiberwert. Wer die BZSt-Abfrage
 *   statt VIES will, tauscht die Implementierung, und ein Auswahlfeld mit einer
 *   Option ist keine Auswahl.
 * - `tax.legal_bases`: die Fundstellen, die zu jeder Entscheidung eingefroren
 *   werden. Sie gehören 1:1 zu den Regeln in {@see TaxRules}; eine Fundstelle
 *   zu ändern, ohne die Regel zu ändern, die sie erzeugt, lässt die Beleglage
 *   lügen. Die *Sätze* dagegen sind die Formulierung des Betreibers und seines
 *   Steuerberaters — die stehen hier.
 * - `pdf.paper`: Papierformat der Ausgabe, ein Deployment-Detail des
 *   gebundenen Renderers.
 * - `tax.prices_include_tax`: **dreiwertig, und die Schicht kann nur zwei.**
 *   Die Paketvorgabe ist „nicht beantwortet", und das ist etwas anderes als
 *   netto: {@see TaxRules} reicht null durch, {@see TaxResult::split()}
 *   verweigert dann die Aufteilung, statt still netto anzunehmen. Ein
 *   `boolean`-Feld hätte diesen Zustand beim ersten Speichern zu „netto"
 *   gemacht — ein falscher Betrag auf jeder Rechnung, ohne dass jemand etwas
 *   getan hätte. Ein nullable `select` mit „1"/„0" war der Versuch daneben und
 *   ist am 07.09.2026 im Playground gescheitert: gespeichert würde eine
 *   Zeichenkette, in `config` steht auf jeder eingerichteten Installation aber
 *   ein `bool`, und die `Select`-Komponente der Schicht nimmt keinen booleschen
 *   `modelValue` — die ganze Seite blieb leer, für alle Addons. Der Schlüssel
 *   bleibt deshalb in `config/invoices.php` und `.env`, bis die Schicht einen
 *   Typ hat, der drei Zustände trägt.
 *
 * **Beim Booten gelesen: nichts.** `SettingsManager::apply()` läuft aus
 * `app->booted()`, alles davor sieht noch den Paketwert. Geprüft wurde jeder
 * Schlüssel: `routes/web.php` liest keine Konfiguration, die Utility-Anmeldung
 * auch nicht, einen Schedule hat dieses Addon nicht, und die beiden
 * Container-Bindungen (`VatIdVerifier`, `BuyerAdmission`) lesen erst beim
 * Auflösen. Es fällt deshalb kein Schlüssel aus diesem Grund weg.
 *
 * **Geheimnisse gibt es hier keine.** Dieses Addon hat keine — IBAN und
 * USt-IdNr. stehen auf jeder Rechnung, die es schreibt, und sind damit das
 * Gegenteil eines Geheimnisses.
 */
class Settings implements ProvidesSettings
{
    /**
     * Der Namensraum, den jede gespeicherte Zeile dieses Addons trägt.
     *
     * `invoices`, der Handle des Addons: bereits Config-Wurzel, Lang-Namensraum
     * und `extra.statamic.slug` in der composer.json. Er steht in
     * `brand_settings.namespace` auf jeder Zeile — ihn später umzubenennen
     * verwaist jede Abweichung, die eine Installation gesetzt hat.
     */
    public static function settingsNamespace(): string
    {
        return 'invoices';
    }

    /**
     * Die Config-Wurzel, der nicht gesetzte Werte weiter folgen.
     *
     * `config/invoices.php`, gemerged unter `invoices`. Getrennt gefragt statt
     * dem Namensraum gleichgesetzt — hier fallen sie zusammen, das ist keine
     * Regel.
     */
    public static function settingsConfigPath(): string
    {
        return 'invoices';
    }

    /**
     * Das Recht, das diesen Abschnitt bewacht.
     *
     * Hingeschrieben, nicht abgeleitet: der Vertrag fragt danach, damit die
     * drei Addons, die vor der Schicht ein eigenes Recht hatten, ihres behalten
     * können. Dieses Addon hatte keins, also gilt die Regel vom 06.09.2026 —
     * `manage <handle> settings` mit dem Paketnamen ohne `statamic-`-Präfix.
     * Angemeldet wird es im Service Provider dieses Addons, wie jedes andere
     * auch; die Schicht fragt nur, welches sie prüfen soll.
     */
    public static function settingsPermission(): string
    {
        return 'manage invoices settings';
    }

    /**
     * @return array<int, array{title: string, description: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('invoices::settings.groups.seller.title'),
                'description' => __('invoices::settings.groups.seller.description'),
                'fields' => [
                    static::field('seller.name', 'string'),
                    // Mehrzeilig: die Vorlage setzt den Absender mit
                    // `white-space: pre-line` (invoice.blade.php), Zeilenumbrüche
                    // in der Anschrift sind also sichtbar und gewollt.
                    static::field('seller.address', 'text'),
                    static::field('seller.vat_id', 'string'),
                    static::field('seller.tax_number', 'string'),
                    static::field('seller.email', 'string'),
                    static::field('seller.iban', 'string'),
                ],
            ],
            [
                'title' => __('invoices::settings.groups.number.title'),
                'description' => __('invoices::settings.groups.number.description'),
                'fields' => [
                    static::field('number.prefix', 'string'),
                    static::field('number.pad', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('invoices::settings.groups.issuing.title'),
                'description' => __('invoices::settings.groups.issuing.description'),
                'fields' => [
                    static::field('auto_issue', 'boolean'),
                    // § 33 UStDV, in Cent. Kein Nullboden-Sonderfall: 0 hieße,
                    // dass es keine Kleinbetragsrechnung gibt, und das ist eine
                    // gültige Antwort.
                    static::field('small_amount_cent', 'integer', ['min' => 0]),
                    static::field('display_timezone', 'string'),
                    static::field('delivery.enabled', 'boolean'),
                    static::field('delivery.subject', 'string'),
                    static::field('delivery.filename', 'string'),
                ],
            ],
            [
                'title' => __('invoices::settings.groups.small_business.title'),
                'description' => __('invoices::settings.groups.small_business.description'),
                'fields' => [
                    static::field('tax.small_business.enabled', 'boolean'),
                    static::field('tax.small_business.eu_scheme', 'boolean'),
                    static::field('tax.small_business.eu_threshold_mode', 'select', [
                        'options' => static::options('eu_threshold_mode', ['below', 'above']),
                    ]),
                ],
            ],
            [
                'title' => __('invoices::settings.groups.buyers.title'),
                'description' => __('invoices::settings.groups.buyers.description'),
                'fields' => [
                    static::field('tax.business_only.enabled', 'boolean'),
                    static::field('tax.business_only.require_company', 'boolean'),
                    static::field('tax.vat_id_check.enabled', 'boolean'),
                    static::field('tax.vat_id_check.timeout', 'integer', ['min' => 1]),
                    // 0 heißt „nie zwischenspeichern", und das muss erreichbar
                    // bleiben.
                    static::field('tax.vat_id_check.cache_hours', 'integer', ['min' => 0]),
                ],
            ],
            [
                'title' => __('invoices::settings.groups.zones.title'),
                'description' => __('invoices::settings.groups.zones.description'),
                'fields' => [
                    static::field('tax.merchant_country', 'string'),
                    static::field('tax.merchant_vat_id', 'string'),
                    static::field('tax.default_product_class', 'string'),
                    static::field('tax.assume_country_when_missing', 'string'),
                    static::field('tax.oss.destination_taxation', 'boolean'),
                    static::field('tax.oss.shipped_rates', 'boolean'),
                ],
            ],
            [
                'title' => __('invoices::settings.groups.texts.title'),
                'description' => __('invoices::settings.groups.texts.description'),
                'fields' => [
                    static::field('tax.texts.small_business', 'string'),
                    static::field('tax.texts.small_business_eu', 'string'),
                    static::field('tax.texts.reverse_charge', 'string'),
                    static::field('tax.texts.intra_community_supply', 'string'),
                    static::field('tax.texts.export', 'string'),
                    static::field('tax.texts.outside_scope', 'string'),
                    static::field('tax.texts.zero_rate', 'string'),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Erklärung aus den Sprachdateien.
     *
     * Der Übersetzungsschlüssel ist der Config-Pfad mit Unterstrichen statt
     * Punkten: ein Punkt ist dem Übersetzer ein Pfadtrenner, und
     * `settings.fields.tax.texts.export.label` würde als fünf verschachtelte
     * Arrays gesucht, die es nicht gibt.
     *
     * **`nullable` ist hier die Vorgabe, und das ist eine Entscheidung, keine
     * Bequemlichkeit.** Sie kostete am 07.09.2026 einen Anlauf: Das Formular
     * schickt immer alle Felder eines Namensraums, und `required` heißt
     * deshalb, dass ein Feld ohne Wert den **ganzen Abschnitt** unspeicherbar
     * macht — auch die Felder daneben, die jemand gerade ändern wollte. Wert
     * hat ein Feld aber nur, solange die Konfiguration ihn liefert, und ein
     * Host, der `config/invoices.php` veröffentlicht und den `tax`-Block
     * kürzt, bekommt für die gekürzten Schlüssel `null` (Laravels
     * `mergeConfigFrom` mischt nicht tief genug hinein). Genau so steht der
     * Playground da: zehn Felder „erforderlich", nichts speicherbar, und der
     * Grund steht auf dem Bildschirm nirgends.
     *
     * Leer ist außerdem bei jedem dieser Werte etwas Definiertes.
     * {@see TaxRules::cfg()} gibt bei `null` seine eigene Vorgabe zurück,
     * {@see NumberSeries} castet `null` zu `''` beziehungsweise auf seinen
     * eigenen Boden. Ein leeres Feld heißt also „kein eigener Wert", was genau
     * das ist, was die Schicht ohnehin verspricht.
     *
     * Für `boolean` ist die Angabe wirkungslos — die Regel dort ist
     * `present|boolean` — und deshalb steht sie einmal für alle statt
     * feldweise.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("invoices::settings.fields.{$handle}.label"),
            'description' => __("invoices::settings.fields.{$handle}.description"),
            'nullable' => true,
        ], $extra);
    }

    /**
     * Optionen für ein `select`, beschriftet aus den Sprachdateien.
     *
     * @param  array<int, string>  $values
     * @return array<int, array{value: string, label: string}>
     */
    protected static function options(string $set, array $values): array
    {
        return array_map(fn (string $value) => [
            'value' => $value,
            'label' => __("invoices::settings.options.{$set}.{$value}"),
        ], $values);
    }
}
