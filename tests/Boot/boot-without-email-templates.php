<?php

/*
 * Boots the addon the way a site without goldnead/statamic-email-templates
 * does. Run in its own PHP process by BootWithoutEmailTemplatesTest: the
 * sibling is a dev dependency here, so it is hidden from the autoloader. Any
 * line of the mail integration that touches one of its classes before asking
 * for the name dies with "Class not found" / "Interface not found".
 *
 * Prints the integration state on success.
 */

$loader = require __DIR__.'/../../vendor/autoload.php';

$loader->unregister();
spl_autoload_register(function (string $class) use ($loader): void {
    if (str_starts_with($class, 'Goldnead\\EmailTemplates\\')) {
        return;
    }

    $loader->loadClass($class);
}, true, true);

foreach ([
    'Goldnead\\EmailTemplates\\Facades\\EmailTemplates',
    'Goldnead\\EmailTemplates\\Contracts\\EmailTemplateSource',
] as $sibling) {
    if (class_exists($sibling) || interface_exists($sibling)) {
        fwrite(STDERR, "precondition: {$sibling} is reachable, this check proves nothing\n");
        exit(2);
    }
}

use Goldnead\Invoices\Integrations\EmailTemplates\InvoiceMailTemplate;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\InvoiceItem;
use Goldnead\Invoices\ServiceProvider;
use Orchestra\Testbench\Foundation\Application;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

$app->register(ServiceProvider::class);

$template = $app->make(InvoiceMailTemplate::class);

// Unsaved, with its lines set by hand: no database is needed or touched.
$invoice = new Invoice(['number' => 'PROBE-1', 'gross_cent' => 11900, 'currency' => 'EUR', 'buyer_email' => 'probe@example.com']);
$invoice->setRelation('items', collect([new InvoiceItem(['name' => 'Chorleitungskurs'])]));

echo 'booted, registered '.($template->register() ? 'yes' : 'no')
    .', template '.($template->render($invoice) === null ? 'none' : 'used')
    .', sources '.count([...$app->tagged('email-templates.sources')])
    .', product '.InvoiceMailTemplate::product($invoice)."\n";
