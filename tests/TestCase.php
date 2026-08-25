<?php

namespace Goldnead\Invoices\Tests;

use Goldnead\Invoices\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Statamic\Licensing\Outpost;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Testbench never fires Statamic::booted callbacks — AddonServiceProvider
        // returns early because this package is not in the testbench app's addon
        // manifest — so bootAddon(), which loads the migrations, has to be
        // invoked by hand.
        $this->app->getProvider(ServiceProvider::class)?->bootAddon();

        if (is_dir($pfad = __DIR__.'/../vendor/goldnead/statamic-payments/database/migrations')) {
            $this->loadMigrationsFrom($pfad);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->artisan('migrate')->run();
        $this->withoutVite();
        $this->giveTestbenchAComposerLock();
    }

    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            StatamicServiceProvider::class,
            class_exists(\Goldnead\StatamicPayments\ServiceProvider::class)
                ? \Goldnead\StatamicPayments\ServiceProvider::class
                : null,
            ServiceProvider::class,
        ]));
    }

    protected function getPackageAliases($app): array
    {
        return ['Statamic' => Statamic::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.url', 'https://example.test');

        // Neither UTC nor any zone a test uses. Every timestamp on an invoice is
        // evidence of *when*, and with app.timezone=UTC a correct conversion and
        // a missing one look identical.
        $app['config']->set('app.timezone', 'America/Chicago');

        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('queue.default', 'sync');

        // The licensing outpost phones home on Control Panel requests. A suite
        // that only passes with internet access is not a suite.
        $app->singleton(Outpost::class, fn () => new class extends Outpost
        {
            public function __construct() {}

            public function radio() {}

            public function response()
            {
                return [];
            }
        });

        $app['config']->set('statamic-payments.products', [
            'kurs' => ['name' => 'Chorleitungskurs', 'amount_cent' => 24900, 'digital' => true],
            'noten' => ['name' => 'Notenpaket', 'amount_cent' => 1500, 'digital' => false, 'tax_class' => 'reduced'],
        ]);

        $app['config']->set('invoices.seller', [
            'name' => 'Nordlicht Studio',
            'address' => "Beispielweg 1\n20095 Hamburg",
            'vat_id' => 'DE123456789',
        ]);
    }

    protected function giveTestbenchAComposerLock(): void
    {
        $ziel = base_path('composer.lock');

        if (file_exists($ziel)) {
            return;
        }

        if (file_exists($quelle = __DIR__.'/../composer.lock')) {
            @copy($quelle, $ziel);
        }
    }
}
