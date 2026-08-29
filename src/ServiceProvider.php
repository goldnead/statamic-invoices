<?php

namespace Goldnead\Invoices;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Contracts\SenderIdentityResolver;
use Goldnead\Invoices\Integrations\Insights\Gross;
use Goldnead\Invoices\Integrations\Insights\Issued;
use Goldnead\Invoices\Integrations\Insights\Net;
use Goldnead\Invoices\Integrations\Insights\Tax;
use Goldnead\Invoices\Sending\BrandMailer;
use Goldnead\Invoices\Sending\BrandSenderIdentity;
use Goldnead\Invoices\Support\DompdfRenderer;
use Goldnead\Invoices\Support\NumberSeries;
use Illuminate\Support\Facades\Log;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

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
    /**
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can store the class name without
     * constructing anything to find out what it is called. Naming the handle
     * twice is the price of that laziness, and it is the cheaper half of the
     * trade: an install with twenty addons would otherwise build every metric
     * object of every one of them on a request that renders none.
     *
     * The handles are frozen from the moment they are registered — they end up
     * in saved dashboards and in URLs. Renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        Issued::class => 'invoices.issued',
        Net::class => 'invoices.net',
        Gross::class => 'invoices.gross',
        Tax::class => 'invoices.tax',
    ];

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

        // Registered against the resolving translator rather than in
        // bootAddon(): a metric's label and group are asked for while the
        // analytics addon is building its screen, which can happen before this
        // package's own boot has run — and a namespace registered too late
        // shows the reader the translation key instead of the word.
        $langPfad = __DIR__.'/../resources/lang';

        $this->app->resolving('translator', fn ($translator) => $translator->addNamespace('invoices', $langPfad));

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('invoices', $langPfad);
        }
    }

    public function bootAddon()
    {
        $this->bootMigrations()
            ->bootInsights()
            ->bootPublishing();
    }

    protected function bootMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    /**
     * Offer the invoice figures to the analytics addon, if it is there.
     *
     * From an `app->booted()` callback rather than straight from here: the
     * sibling's container bindings only exist once its own provider has booted,
     * and this one may boot first. Registering earlier registers into nothing,
     * silently — an empty screen with no error anywhere, which is the worst
     * shape this failure could take.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a few tiles on a screen nobody has open, never
     * an invoice. The guards are three, and each one covers a real variation of
     * "installed but not quite": the class may be absent, the container may
     * refuse to build the manager, and an older release of the sibling may have
     * the facade without this method on it.
     *
     * The metric classes name the sibling's contract and its base class in
     * their `extends` and `implements`, which is safe precisely because of the
     * first guard: PHP loads a class when something touches it, and nothing
     * touches these unless the facade exists. Hence `suggest` in composer.json
     * rather than `require` — an install of this addon alone must not drag an
     * analytics package in.
     */
    protected function bootInsights(): self
    {
        $this->app->booted(function (): void {
            $fassade = '\Goldnead\StatamicInsights\Facades\Insights';

            if (! class_exists($fassade)) {
                return;
            }

            try {
                $verwalter = $fassade::getFacadeRoot();

                // Asked of the object, never of the facade: a facade forwards
                // through `__callStatic` and declares none of what it forwards,
                // so the probe on the facade itself is always false.
                if (! is_object($verwalter) || ! method_exists($verwalter, 'registerMetric')) {
                    return;
                }

                foreach (self::INSIGHTS_METRICS as $klasse => $handle) {
                    $verwalter->registerMetric($klasse, $handle);
                }
            } catch (Throwable $e) {
                Log::warning('statamic-invoices: the insights metrics could not be registered.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        });

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

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/invoices'),
        ], 'invoices-translations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'invoices');

        return $this;
    }
}
