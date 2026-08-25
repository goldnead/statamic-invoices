<?php

namespace Goldnead\Invoices;

use Goldnead\Invoices\Support\NumberSeries;
use Statamic\Providers\AddonServiceProvider;

/**
 * Declares almost nothing.
 *
 * `src/Listeners/` and `src/Console/Commands/` are found by convention, and the
 * listeners are wired by reflecting the first parameter type of their `handle`
 * methods. An explicit array goes stale the moment somebody adds a class, and
 * the symptom is "my listener does not fire".
 */
class ServiceProvider extends AddonServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/invoices.php', 'invoices');

        $this->app->singleton(NumberSeries::class);
        $this->app->singleton(InvoiceWriter::class);
    }

    public function bootAddon()
    {
        $this->bootMigrations()
            ->bootPublishing();
    }

    protected function bootMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    protected function bootPublishing(): self
    {
        $this->publishes([
            __DIR__.'/../config/invoices.php' => config_path('invoices.php'),
        ], 'invoices-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/invoices'),
        ], 'invoices-views');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'invoices');

        return $this;
    }
}
