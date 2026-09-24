<?php

namespace Goldnead\Invoices\Integrations\WebhookManager;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Offers the invoice moments to `goldnead/statamic-webhook-manager` as
 * triggers, where that addon is installed.
 *
 * The leadhub pattern: one trigger per moment, a listener per event, re-emitted
 * as the manager's `TriggerDetected`. Not `registerEventTrigger()`, because
 * that dispatches in whatever brand is current, and an invoice is written in a
 * payment webhook or a queue worker where none is; the manager's hooks are
 * brand-scoped, so a multi-brand install would reach no hook or the wrong one.
 *
 * **Nothing here loads a class of the webhook manager before its name has been
 * checked as a string.** {@see InvoicesTrigger} implements the manager's
 * interface and is only named after that check.
 */
class WebhookManagerBridge
{
    public const FACADE = 'Goldnead\\WebhookManager\\Facades\\WebhookManager';

    public const CONTRACT = 'Goldnead\\WebhookManager\\Contracts\\TriggerInterface';

    public const DETECTED = 'Goldnead\\WebhookManager\\Events\\TriggerDetected';

    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('invoices.webhook_manager.enabled', true)
            && class_exists(self::FACADE)
            && interface_exists(self::CONTRACT)
            && class_exists(self::DETECTED);
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // The binding exists once the manager's provider booted; bail without
        // marking booted so the retry at the end of the booted queue can work.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        $manager = app('webhook-manager');

        foreach (WebhookPayload::MOMENTS as $moment => $eventClass) {
            try {
                $manager->registerTrigger(new InvoicesTrigger($moment));
            } catch (Throwable $e) {
                Log::warning('statamic-invoices: the webhook manager would not take the trigger ['.WebhookPayload::PREFIX.$moment.'].', [
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $events->listen($eventClass, function (object $event) use ($moment): void {
                $this->dispatch($moment, $event);
            });
        }
    }

    /**
     * Never throws: a receiver that is down must cost a webhook, not an
     * invoice number.
     */
    protected function dispatch(string $moment, object $event): void
    {
        $handle = WebhookPayload::PREFIX.$moment;

        // After the commit, never inside it: a queued delivery could run
        // before the rows exist, a sync one would hold the write lock for an
        // HTTP round trip, and a rollback would leave the receiver told about
        // a number that was never issued. Outside a transaction this runs at
        // once; after a rollback never.
        try {
            DB::afterCommit(fn () => $this->handOver($handle, $event));
        } catch (Throwable $e) {
            Log::warning('statamic-invoices: an invoice moment could not be handed to the webhook manager.', [
                'trigger' => $handle,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function handOver(string $handle, object $event): void
    {
        try {
            $trigger = app('webhook-manager')->triggers()->get($handle);

            if ($trigger === null) {
                return;
            }

            $detected = self::DETECTED;

            WebhookPayload::runForBrand(
                WebhookPayload::brandIdOf($event),
                fn () => event(new $detected($trigger->build($event))),
                $handle,
            );
        } catch (Throwable $e) {
            Log::warning('statamic-invoices: an invoice moment could not be handed to the webhook manager.', [
                'trigger' => $handle,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
