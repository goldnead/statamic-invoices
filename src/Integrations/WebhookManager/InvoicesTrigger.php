<?php

namespace Goldnead\Invoices\Integrations\WebhookManager;

use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;
use Illuminate\Support\Carbon;

/**
 * One invoice moment as a trigger of the webhook manager.
 *
 * **Loaded only after the bridge has checked that interface by name.** This
 * class `implements` it; touching it on an install without the manager is a
 * fatal "Interface not found". Nothing outside
 * {@see WebhookManagerBridge::boot()} may name it.
 */
class InvoicesTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $moment,
    ) {}

    public function handle(): string
    {
        return WebhookPayload::PREFIX.$this->moment;
    }

    /** Asked every time: the CP lists triggers in the viewer's language. */
    public function label(): string
    {
        return (string) __('invoices::webhooks.triggers.'.$this->moment);
    }

    public function description(): ?string
    {
        $key = 'invoices::webhooks.descriptions.'.$this->moment;
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    public function sourceType(): string
    {
        return 'invoices';
    }

    public function build(mixed $source, array $context = []): TriggerEvent
    {
        $at = Carbon::now()->toImmutable();
        $payload = is_object($source) ? WebhookPayload::build($this->moment, $source, $at) : [
            'event' => $this->handle(),
            'occurred_at' => $at->format(\DATE_ATOM),
            'brand' => null,
        ];

        return new TriggerEvent(
            triggerHandle: $this->handle(),
            sourceType: $this->sourceType(),
            sourceReference: is_object($source) ? WebhookPayload::referenceOf($source) : null,
            payload: $payload,
            site: null,
            locale: null,
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: $at,
        );
    }
}
