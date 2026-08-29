<?php

namespace Goldnead\Invoices;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Contracts\SenderIdentityResolver;
use Goldnead\Invoices\Sending\BrandMailer;
use Goldnead\Invoices\Sending\BrandSenderIdentity;
use Goldnead\Invoices\Support\DompdfRenderer;
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

        // Bound to the interface, not used as one. Which engine turns the
        // document into a PDF is an infrastructure decision, and a host that
        // already runs a headless browser — or a print house with a template of
        // its own — rebinds this one line.
        $this->app->bind(PdfRenderer::class, DompdfRenderer::class);

        // Who an invoice is sent as. The sub-interface exists so a host can
        // answer that for invoices alone: the seller named on a tax document is
        // not necessarily the name its newsletter goes out under.
        $this->app->singleton(SenderIdentityResolver::class, BrandSenderIdentity::class);
        $this->app->singleton(BrandMailer::class);
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
