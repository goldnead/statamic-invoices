<?php

/*
 * Boots the addon the way a site without goldnead/statamic-webhook-manager
 * does. Run in its own PHP process by BootWithoutWebhookManagerTest: the
 * manager is a dev dependency here, so it is hidden from the autoloader. A
 * provider or bridge that touches one of its classes before checking the name
 * dies with "Interface not found" / "Class not found".
 *
 * Prints "booted" and the bridge state on success.
 */

$loader = require __DIR__.'/../../vendor/autoload.php';

$loader->unregister();
spl_autoload_register(function (string $class) use ($loader): void {
    if (str_starts_with($class, 'Goldnead\\WebhookManager\\')) {
        return;
    }

    $loader->loadClass($class);
}, true, true);

foreach ([
    'Goldnead\\WebhookManager\\Facades\\WebhookManager',
    'Goldnead\\WebhookManager\\Contracts\\TriggerInterface',
] as $sibling) {
    if (class_exists($sibling) || interface_exists($sibling)) {
        fwrite(STDERR, "precondition: {$sibling} is reachable, this check proves nothing\n");
        exit(2);
    }
}

use Goldnead\Invoices\Events\InvoiceIssued;
use Goldnead\Invoices\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\ServiceProvider;
use Orchestra\Testbench\Foundation\Application;

$app = Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

// register() and boot(); the app is booted, so the queued callbacks run at once.
$app->register(ServiceProvider::class);

// The moment the bridge would hear. Unsaved: no database is needed or touched.
InvoiceIssued::dispatch(new Invoice(['number' => 'PROBE-1']));

echo 'booted, bridge '.($app->make(WebhookManagerBridge::class)->booted() ? 'on' : 'off')
    .', available '.(WebhookManagerBridge::available() ? 'yes' : 'no')."\n";
