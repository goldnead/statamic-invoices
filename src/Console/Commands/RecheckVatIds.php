<?php

declare(strict_types=1);

namespace Goldnead\Invoices\Console\Commands;

use Goldnead\Invoices\Models\Invoice;
use Goldnead\Invoices\Models\VatIdCheckRecord;
use Goldnead\Invoices\Support\BuyerAdmission;
use Goldnead\Invoices\Support\VatIdStatus;
use Illuminate\Console\Command;

/**
 * Asks again about the numbers nobody could confirm the first time.
 *
 * **It does not touch the invoices.** Not because that would be inconvenient
 * but because it would be wrong twice over: the document is immutable by law
 * and by this package's model, and the seller's position rests on what the
 * service said *at the time* (§ 6a Abs. 4 UStG protects exactly that reliance).
 * A later answer is a new fact, so it becomes a new row, and the Control Panel
 * shows both.
 *
 * The exit code carries the one thing worth alerting on: a number that has come
 * back invalid needs a person. Everything else — still pending, now confirmed —
 * is a normal day and exits zero, so this can sit in a nightly schedule without
 * teaching anybody to ignore its output.
 */
class RecheckVatIds extends Command
{
    protected $signature = 'invoices:recheck-vat-ids
        {--limit=100 : How many outstanding invoices to look at in one run}';

    protected $description = 'Ask the confirmation service again about VAT IDs that were still pending when the invoice was written';

    public function handle(BuyerAdmission $admission): int
    {
        $invoices = Invoice::query()
            ->awaitingVatIdConfirmation()
            ->orderBy('issued_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('Nothing outstanding.');

            return self::SUCCESS;
        }

        $confirmed = 0;
        $stillPending = 0;
        $contradicted = [];

        foreach ($invoices as $invoice) {
            $check = $admission->check((string) $invoice->buyer_vat_id);

            // Written on every run, including the boring ones. A command that only
            // records the interesting cases cannot answer "was this one ever looked
            // at again", which is the question a tax office asks.
            $invoice->vatIdChecks()->create(VatIdCheckRecord::columnsFrom($check));

            match ($check->status) {
                VatIdStatus::Valid => $confirmed++,
                VatIdStatus::Pending, VatIdStatus::Unchecked => $stillPending++,
                VatIdStatus::Invalid => $contradicted[] = (string) $invoice->number,
            };
        }

        $this->line(sprintf(
            '%d looked at: %d confirmed, %d still pending, %d came back invalid.',
            $invoices->count(),
            $confirmed,
            $stillPending,
            count($contradicted),
        ));

        if ($contradicted === []) {
            return self::SUCCESS;
        }

        // Named, not counted. "2 invalid" sends somebody to the Control Panel to
        // find out which two; the numbers are what they need in order to act.
        $this->error(sprintf(
            'These invoices carry a VAT ID the service now rejects: %s. They need a decision — '
            .'a credit note plus a corrected invoice, or none, depending on the case.',
            implode(', ', $contradicted),
        ));

        return self::FAILURE;
    }
}
