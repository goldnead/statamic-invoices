<?php

namespace Goldnead\Invoices\Integrations\EmailTemplates;

use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Mail\InvoiceMail;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\DisplayTime;
use Goldnead\Invoices\Support\Money;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Die Rechnungsmail als Vorlage in goldnead/statamic-email-templates.
 *
 * **Angemeldet, nicht übernommen.** Die Mail meldet sich in der Registry des
 * Nachbarn an (`email-templates.registry`): wer sie schickt, bei welchem
 * Anlass, welche Platzhalter sie kennt, und ihr Wortlaut als Vorgabe. Im CP
 * steht sie damit unter „Invoices", ein Import schreibt die Vorgabe als
 * Eintrag, und ab dann schreibt dieser Eintrag Betreff und Text.
 *
 * **Ohne Eintrag bleibt alles, wie es war.** Die eingebaute Ansicht
 * (`invoices::mail.invoice`) mit Logo als CID-Anhang und Betragskasten ist die
 * Mail, die eine Installation heute verschickt. Eine Vorlage, die noch niemand
 * angelegt hat, darf sie nicht ersetzen: das wäre eine neue Mail, die niemand
 * freigegeben hat, an Käufer, die sie zehn Jahre aufheben.
 *
 * Die PDF hängt in beiden Fällen an; das macht {@see InvoiceMail}.
 *
 * Nur Zeichenketten-Klassennamen und `app()->bound()`: dieses Addon läuft ohne
 * den Nachbarn, und keine Zeile hier lädt eine seiner Klassen, wenn er fehlt.
 */
class InvoiceMailTemplate
{
    public const REGISTRY = 'email-templates.registry';

    public const FACADE = '\Goldnead\EmailTemplates\Facades\EmailTemplates';

    public const MERGE = '\Goldnead\EmailTemplates\Support\MergeVariables';

    /** Die Platzhalter, mit einem Beispiel je Stück für Vorschau und Testversand. */
    public const PLACEHOLDERS = [
        'buyer.name' => 'Maria Beispiel',
        'buyer.email' => 'maria.beispiel@example.com',
        'invoice.number' => 'RE2026-09-001',
        'invoice.date' => '25.09.2026',
        'amount' => '119,00 €',
        'seller.name' => 'Nordlicht Studio',
        'site_name' => 'Nordlicht Studio',
        'product' => 'Chorleitungskurs',
        'portal_url' => 'https://example.com/!/statamic-payments/konto/anmelden',
    ];

    public const SOURCE_CONTRACT = '\Goldnead\EmailTemplates\Contracts\EmailTemplateSource';

    public function slug(): string
    {
        $slug = config('invoices.delivery.template');

        return is_string($slug) ? trim($slug) : '';
    }

    /**
     * Bei der Registry anmelden, wenn es sie gibt. Wahr, wenn angemeldet.
     *
     * Ein email-templates vor 2.8 hat keine Registry, aber den Import aus
     * markierten Quellen: dann meldet sich die Mail dort als
     * {@see TemplateSource} an, und `email-templates:import` legt den Eintrag
     * an. Nie beides, sonst sähe der Import dieselbe Vorlage zweimal.
     */
    public function register(): bool
    {
        $slug = $this->slug();

        if ($slug === '') {
            return false;
        }

        if (! app()->bound(self::REGISTRY)) {
            if (interface_exists(self::SOURCE_CONTRACT)) {
                app()->tag([TemplateSource::class], 'email-templates.sources');

                return true;
            }

            return false;
        }

        // Bound only by an email-templates with the registry, and that one has
        // `register()`; no version checks beyond the binding itself.
        $registry = app(self::REGISTRY);

        $placeholders = [];

        foreach (self::PLACEHOLDERS as $name => $example) {
            $placeholders[$name] = [
                'label' => fn () => (string) __('invoices::mail.placeholders.'.str_replace('.', '_', $name)),
                'example' => $example,
            ];
        }

        $registry->register([
            'slug' => $slug,
            'addon' => 'Invoices',
            'title' => fn () => (string) __('invoices::mail.title'),
            'trigger' => fn () => (string) __('invoices::mail.trigger'),
            'event' => InvoiceIssued::class,
            'placeholders' => $placeholders,
            'defaults' => fn () => $this->defaults(),
        ]);

        return true;
    }

    /**
     * Der mitgelieferte Wortlaut, derselbe wie in der eingebauten Ansicht.
     *
     * @return array{title: string, subject: string, body: string}
     */
    public function defaults(): array
    {
        return [
            'title' => (string) __('invoices::mail.title'),
            'subject' => (string) __('invoices::mail.subject'),
            'body' => (string) __('invoices::mail.body'),
        ];
    }

    /**
     * Betreff und HTML aus dem Eintrag, oder null, wenn es keinen gibt.
     *
     * Null heißt: die eingebaute Mail geht raus. Auch wenn der Nachbar beim
     * Auflösen scheitert, denn eine Rechnung, die wegen einer Vorlage nicht
     * zugestellt wird, ist schlimmer als eine im Standardwortlaut. Der Fehler
     * wird gemeldet, nicht verschluckt.
     *
     * @return array{subject: string, html: string}|null
     */
    public function render(Invoice $invoice): ?array
    {
        $slug = $this->slug();

        if ($slug === '' || ! class_exists(self::FACADE)) {
            return null;
        }

        try {
            $facade = self::FACADE;
            $template = $facade::resolve($slug);

            if ($template === null || trim($template->body) === '') {
                return null;
            }

            $variables = $this->variables($invoice);

            return [
                'subject' => $this->merge($template->subject, $variables, false),
                'html' => $this->merge($template->body, $variables, true),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(Invoice $invoice): array
    {
        $seller = (array) ($invoice->seller ?? []);

        return [
            'buyer' => ['name' => static::greetingName($invoice), 'email' => (string) $invoice->buyer_email],
            'invoice' => [
                'number' => (string) $invoice->number,
                'date' => DisplayTime::of($invoice->issued_at)->format('d.m.Y'),
            ],
            'amount' => Money::format($invoice->gross_cent, $invoice->currency),
            'seller' => ['name' => (string) ($seller['name'] ?? '')],
            'site_name' => (string) config('app.name'),
            'product' => static::product($invoice),
            'portal_url' => static::portalUrl(),
        ];
    }

    /**
     * What the mail calls the buyer: the name stored on the invoice, else the
     * part of the address before the `@`, never the whole address.
     *
     * No lookup of a user or customer account in between: an invoice stores no
     * link to one (only `payment_id`, and the payment has the same name this
     * row already copied from it), and an invoice does not reach back for data
     * that can change. The full address stays in `buyer.email`.
     */
    public static function greetingName(Invoice $invoice): string
    {
        $name = trim((string) $invoice->buyer_name);

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) $invoice->buyer_email);
        $local = strstr($email, '@', true);

        return is_string($local) && trim($local) !== '' ? trim($local) : $email;
    }

    /**
     * Was gekauft wurde, wortgleich wie auf der Rechnung: die Namen der
     * Positionen, mit Komma getrennt, jeder einmal. Kein zweiter Blick in den
     * Katalog. Nennt die Mail etwas anderes als das Dokument, ist eines von
     * beiden falsch.
     */
    public static function product(Invoice $invoice): string
    {
        $names = [];

        foreach ($invoice->items as $line) {
            $name = trim((string) $line->name);

            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return implode(', ', $names);
    }

    /**
     * Der Weg ins Kundenkonto von statamic-payments, wo die Rechnung zum
     * Herunterladen liegt. Die Anmeldeseite, kein signierter Link: eine
     * Rechnungsmail liegt Jahre im Postfach, ein Zugang darin wäre ein
     * Geheimnis, das so lange gilt. Leer, wo es das Konto nicht gibt.
     */
    public static function portalUrl(): string
    {
        try {
            return Route::has('statamic-payments.portal.request')
                ? (string) route('statamic-payments.portal.request')
                : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    protected function merge(string $text, array $variables, bool $escape): string
    {
        if (class_exists(self::MERGE)) {
            $merge = self::MERGE;

            return (string) $merge::apply($text, $variables, $escape);
        }

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $m) use ($variables, $escape) {
            $value = data_get($variables, $m[1]);

            if (! is_scalar($value)) {
                return $m[0];
            }

            return $escape ? e((string) $value) : (string) $value;
        }, $text);
    }
}
