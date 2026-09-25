<?php

namespace Goldnead\Invoices\Integrations\EmailTemplates;

use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Mail\InvoiceMail;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\DisplayTime;
use Goldnead\Invoices\Support\Money;
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
    ];

    public function slug(): string
    {
        $slug = config('invoices.delivery.template');

        return is_string($slug) ? trim($slug) : '';
    }

    /**
     * Bei der Registry anmelden, wenn es sie gibt. Wahr, wenn angemeldet.
     */
    public function register(): bool
    {
        $slug = $this->slug();

        if ($slug === '' || ! app()->bound(self::REGISTRY)) {
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
        $name = is_string($invoice->buyer_name) && trim($invoice->buyer_name) !== ''
            ? trim($invoice->buyer_name)
            : (string) $invoice->buyer_email;

        return [
            'buyer' => ['name' => $name, 'email' => (string) $invoice->buyer_email],
            'invoice' => [
                'number' => (string) $invoice->number,
                'date' => DisplayTime::of($invoice->issued_at)->format('d.m.Y'),
            ],
            'amount' => Money::format($invoice->gross_cent, $invoice->currency),
            'seller' => ['name' => (string) ($seller['name'] ?? '')],
            'site_name' => (string) config('app.name'),
        ];
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
