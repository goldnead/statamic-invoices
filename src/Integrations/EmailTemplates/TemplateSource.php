<?php

namespace Goldnead\Invoices\Integrations\EmailTemplates;

use Goldnead\EmailTemplates\Contracts\EmailTemplateSource;
use Goldnead\EmailTemplates\Support\EmailTemplateData;

/**
 * Hands the shipped invoice mail to `php artisan email-templates:import` on an
 * email-templates older than 2.8, which has no registry yet. The import turns
 * it into an ordinary entry an editor can change.
 *
 * **Implements a sibling's interface**, so it is only named after
 * `interface_exists()` has been asked by string, see
 * {@see InvoiceMailTemplate::register()}.
 */
class TemplateSource implements EmailTemplateSource
{
    public function label(): string
    {
        return 'Invoices';
    }

    public function all(): array
    {
        $template = app(InvoiceMailTemplate::class);
        $slug = $template->slug();

        if ($slug === '') {
            return [];
        }

        return [EmailTemplateData::fromArray($template->defaults() + ['slug' => $slug, 'source' => 'invoices'])];
    }
}
