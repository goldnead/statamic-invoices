<?php

namespace Goldnead\Invoices\Delivery;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Events\InvoiceDelivered;
use Goldnead\Invoices\Mail\InvoiceMail;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Sending\BrandMailer;
use Illuminate\Support\Facades\Log;

/**
 * Puts an existing invoice in the buyer's mailbox.
 *
 * **It never creates one.** Everything it needs is already on the row, and the
 * only way in is an invoice somebody else wrote. That is not politeness, it is
 * the constraint: if delivery could write, then a mandatory detail missing
 * under § 14 UStG would stop the writer and be waved through by the sender —
 * and the second document would be the one nobody checked.
 *
 * **It reads, it does not recompute.** The PDF is rendered from stored values,
 * so the copy sent today and the copy downloaded next year are the same
 * document.
 */
class InvoiceDelivery
{
    public function __construct(
        protected PdfRenderer $pdf,
        protected BrandMailer $mailer,
    ) {}

    /**
     * @return bool whether it went out
     */
    public function send(Invoice $invoice): bool
    {
        $to = trim((string) $invoice->buyer_email);

        if ($to === '') {
            // A paid order with no address. `statamic-payments` already says so
            // at fulfilment; repeating it here is what tells an operator that
            // this particular invoice is sitting in the database undelivered.
            Log::warning('invoices: '.$invoice->number.' has no buyer address, so it was not sent.', [
                'invoice_id' => $invoice->getKey(),
            ]);

            return false;
        }

        // The brand frozen onto the invoice, never `null`. `null` would mean
        // "whatever brand is in context", and nothing is in context in a
        // provider's webhook or a console run — which is exactly where invoices
        // are written. On a single-brand install this is `0`, no brand row
        // matches, and the identity falls through to the host's configuration,
        // unchanged.
        $brandId = (int) $invoice->brand_id;

        // Asked before the document is built, not after it failed. That is what
        // `maySend()` is for, and here it saves rendering a PDF that would be
        // thrown away — a refused sender identity is a configuration fault, so
        // it is the case that repeats for every invoice until somebody fixes it.
        if (! $this->mailer->maySend($brandId)) {
            return $this->refused($invoice, $brandId);
        }

        $sent = $this->mailer->send(
            $brandId,
            $to,
            $invoice->buyer_name,
            new InvoiceMail($invoice, $this->pdf->render($invoice), $this->filename($invoice)),
        );

        if (! $sent) {
            // Checked twice on purpose: a queue worker lives for days, and the
            // brand row or the mail config can change between the two calls.
            return $this->refused($invoice, $brandId);
        }

        InvoiceDelivered::dispatch($invoice, $to);

        $this->logOnPayment($invoice, $to);

        return true;
    }

    /**
     * Into the payment's communication log, where the payments addon offers
     * one. "Did the invoice go out" is a question asked at the order, and the
     * detail screen over there answers it from this line.
     *
     * By string, behind `class_exists`: an older payments release has no
     * `PaymentLog`, and this must not turn a delivered invoice into a fatal
     * error. The facade itself swallows and logs a failed write; it never
     * throws into a mail path.
     */
    protected function logOnPayment(Invoice $invoice, string $to): void
    {
        $facade = '\Goldnead\StatamicPayments\Facades\PaymentLog';

        if ($invoice->payment_id === null || ! class_exists($facade)) {
            return;
        }

        $subject = str_replace(':number', (string) $invoice->number, (string) config('invoices.delivery.subject', 'Ihre Rechnung :number'));

        $facade::mail((int) $invoice->payment_id, 'invoice', $to, $subject, 'sent', [
            'invoice' => $invoice->number,
            'invoice_id' => $invoice->getKey(),
        ]);
    }

    /**
     * The sender identity was refused, so nothing went out.
     *
     * `BrandMailer` has already logged the reason. What it cannot know is which
     * document did not go out, and "one brand cannot send" is a sentence
     * somebody can act on only once it names the invoice.
     */
    protected function refused(Invoice $invoice, int $brandId): bool
    {
        Log::warning('invoices: '.$invoice->number.' was not sent; the sender identity was refused.', [
            'invoice_id' => $invoice->getKey(),
            'brand_id' => $brandId,
        ]);

        return false;
    }

    /**
     * The name the file carries into the buyer's downloads folder.
     *
     * The invoice number is in it because that is what somebody searches for
     * three years later. Everything that is not a letter, a digit, a dash or a
     * dot is dropped: the number comes from configuration, and a prefix with a
     * slash in it would otherwise become a path.
     */
    protected function filename(Invoice $invoice): string
    {
        $muster = (string) config('invoices.delivery.filename', 'Rechnung-:number.pdf');

        $name = str_replace(':number', (string) $invoice->number, $muster);

        return preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?: 'Rechnung.pdf';
    }
}
