<?php

namespace Goldnead\Invoices\Mail;

use Goldnead\BrandContext\Sending\BrandMailer;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Support\Money;
use Illuminate\Mail\Mailable;

/**
 * The mail that carries an invoice.
 *
 * Short on purpose. The document is the message; a covering letter that
 * explains, sells or thanks only stands between the buyer and the file they
 * actually need — and this mail is kept for ten years alongside it.
 *
 * **Not `ShouldQueue`, and no `Queueable`/`SerializesModels`.** A queued
 * mailable is built after the send returns, in another process, with the brand
 * sender identity no longer in place — {@see BrandMailer} refuses one outright.
 * Advertising the traits would suggest a route that does not exist. A host that wants this off the request queues the surrounding job
 * and carries the invoice id on it.
 */
class InvoiceMail extends Mailable
{
    public function __construct(
        public Invoice $invoice,
        protected string $pdf,
        protected string $filename,
    ) {}

    public function build(): self
    {
        // Only when nobody has already decided. `build()` runs at delivery,
        // after `BrandMailer` has put the brand's own address on the mailable,
        // so an unguarded assignment here would quietly undo it — and the
        // address is the half of the pair the relay checks against the account
        // the transport belongs to.
        if (empty($this->from)) {
            $this->from(...$this->fallbackSender());
        }

        return $this
            ->subject($this->fill((string) config('invoices.delivery.subject', 'Ihre Rechnung :number')))
            ->view('invoices::mail.invoice', [
                'invoice' => $this->invoice,
                'seller' => (array) ($this->invoice->seller ?? []),
                'betrag' => Money::format($this->invoice->gross_cent, $this->invoice->currency),
            ])
            ->attachData($this->pdf, $this->filename, ['mime' => 'application/pdf']);
    }

    /**
     * Who this comes from when no brand declared an identity.
     *
     * The seller frozen onto **this** invoice first, and the host-wide address
     * only after that. The order is the point: `invoices.seller_per_brand` lets
     * each brand name its own sender, and that value is already on the row. On
     * a multi-brand install where nobody filled in `settings.mail`, falling
     * straight through to `config('mail.from')` would post one brand's invoice
     * under another brand's name — over a document that names the first brand
     * as the seller in the same breath.
     *
     * @return array{0: string|null, 1: string|null}
     */
    protected function fallbackSender(): array
    {
        $seller = (array) ($this->invoice->seller ?? []);

        $address = is_string($seller['email'] ?? null) && trim($seller['email']) !== ''
            ? trim($seller['email'])
            : config('mail.from.address');

        $name = is_string($seller['name'] ?? null) && trim($seller['name']) !== ''
            ? trim($seller['name'])
            : config('mail.from.name');

        return [$address, $name];
    }

    protected function fill(string $muster): string
    {
        return str_replace(':number', (string) $this->invoice->number, $muster);
    }
}
