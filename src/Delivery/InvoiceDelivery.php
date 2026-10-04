<?php

namespace Goldnead\Invoices\Delivery;

use Goldnead\Invoices\Contracts\PdfRenderer;
use Goldnead\Invoices\Events\InvoiceDelivered;
use Goldnead\Invoices\Mail\InvoiceMail;
use Goldnead\Invoices\Models\DeliveryRecord;
use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Sending\BrandMailer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        protected ZeroTaxGuard $guard,
    ) {}

    /**
     * @return bool whether it went out
     */
    public function send(Invoice $invoice): bool
    {
        // Once per invoice, whatever calls this how often. A row here means the
        // invoice was sent, is being sent, or was held back on purpose; each of
        // the three is an answer a second attempt must not overrule.
        if (DeliveryRecord::query()->where('invoice_id', $invoice->getKey())->exists()) {
            return false;
        }

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

        // After the address and the sender, before the document: a held invoice
        // is a decision about this one document, and it is recorded so that no
        // later attempt sends it after all.
        $deviations = $this->guard->deviations($invoice);

        if ($deviations !== []) {
            return $this->hold($invoice, $to, $deviations);
        }

        // The claim. The unique index on `invoice_id` lets exactly one attempt
        // through; a concurrent second one lands in the catch and stops.
        try {
            $claim = DeliveryRecord::create([
                'invoice_id' => $invoice->getKey(),
                'status' => DeliveryRecord::STATUS_SENDING,
                'recipient' => $to,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        try {
            $mail = new InvoiceMail($invoice, $this->pdf->render($invoice), $this->filename($invoice));

            $sent = $this->mailer->send($brandId, $to, $invoice->buyer_name, $mail);
        } catch (Throwable $e) {
            // Nothing went out, so the way stays open for the next attempt.
            $claim->delete();

            throw $e;
        }

        if (! $sent) {
            $claim->delete();

            // Checked twice on purpose: a queue worker lives for days, and the
            // brand row or the mail config can change between the two calls.
            return $this->refused($invoice, $brandId);
        }

        $claim->update([
            'status' => DeliveryRecord::STATUS_SENT,
            'subject' => mb_substr((string) $mail->subject, 0, 255) ?: null,
        ]);

        InvoiceDelivered::dispatch($invoice, $to);

        $this->logOnPayment($invoice, $to, $mail->subject);

        return true;
    }

    /**
     * Not sent, on purpose, and said where somebody will see it.
     *
     * The record stays: a second attempt would find the same document. The
     * way out is a person: check the tax question, cancel the invoice with a
     * credit note, fix the cause, write it again. Releasing this one instead
     * (deleting its row in `invoice_deliveries`) sends it as it is.
     *
     * @param  list<array<string, mixed>>  $deviations
     */
    protected function hold(Invoice $invoice, string $to, array $deviations): bool
    {
        try {
            DeliveryRecord::create([
                'invoice_id' => $invoice->getKey(),
                'status' => DeliveryRecord::STATUS_HELD,
                'recipient' => $to,
                'reason' => ZeroTaxGuard::REASON,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        Log::error(ZeroTaxGuard::LOG_HELD, [
            'invoice_id' => $invoice->getKey(),
            'number' => $invoice->number,
            'payment_id' => $invoice->payment_id,
            'buyer_country' => $invoice->buyer_country,
            'tax_reason' => $invoice->tax_reason,
            'lines' => $deviations,
            'runbook' => 'Clarify the tax question for this country and product. Then cancel the invoice '
                .'(Invoices::creditNoteFor), fix the configuration, write it again and send it. To send '
                .'it unchanged instead, delete its row in invoice_deliveries and call InvoiceDelivery::send().',
        ]);

        $this->logOnPayment($invoice, $to, null, 'failed', ['held' => ZeroTaxGuard::REASON]);

        return false;
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
    /**
     * @param  array<string, mixed>  $meta
     */
    protected function logOnPayment(Invoice $invoice, string $to, ?string $sentSubject = null, string $status = 'sent', array $meta = []): void
    {
        $facade = '\Goldnead\StatamicPayments\Facades\PaymentLog';

        if ($invoice->payment_id === null || ! class_exists($facade)) {
            return;
        }

        // The subject that actually went out: an email-templates entry writes
        // its own, and the log should not claim the configured one.
        $subject = is_string($sentSubject) && $sentSubject !== ''
            ? $sentSubject
            : str_replace(':number', (string) $invoice->number, (string) config('invoices.delivery.subject', 'Ihre Rechnung :number'));

        $facade::mail((int) $invoice->payment_id, 'invoice', $to, $subject, $status, [
            'invoice' => $invoice->number,
            'invoice_id' => $invoice->getKey(),
            ...$meta,
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
