<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was known about the buyer's VAT ID when the invoice was written, and
 * where a later look at it goes.
 *
 * Two halves, and the split is the point.
 *
 * **On the invoice**: the verdict, the moment, the service and its reference,
 * frozen like everything else on the document. § 14a Abs. 1 UStG wants the
 * buyer's number printed; a tax office asking whether it was confirmed wants to
 * know when and by whom, and a lookup at render time would answer with today's
 * state of a foreign register rather than the one the seller relied on.
 *
 * **In its own table**: every later look. An invoice does not change — that is
 * enforced on the model, not merely agreed — so a re-check cannot write to it.
 * It writes a row here instead. Which is also the more useful shape: "confirmed
 * on the 5th, still confirmed on the 12th" is a history, and a column would only
 * ever hold the last line of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Which of the three cases this document is: de, eu-b2b,
            // third-country-b2b. Stored rather than derived, because deriving it
            // means re-running today's rules against an old row — and the rules are
            // exactly the thing that may have changed in between.
            $table->string('tax_zone', 24)->nullable()->after('buyer_vat_id');

            // valid, invalid, pending, unchecked. Nullable, because an invoice
            // without a buyer VAT ID has no check to record, and "no check" is not
            // the same claim as "checked and found wanting".
            $table->string('buyer_vat_id_status', 16)->nullable()->after('tax_zone');
            $table->timestamp('buyer_vat_id_checked_at')->nullable()->after('buyer_vat_id_status');
            $table->string('buyer_vat_id_service', 32)->nullable()->after('buyer_vat_id_checked_at');
            // VIES hands out one reference per qualified enquiry. It is the only
            // thing that turns "we checked" into something a third party can follow.
            $table->string('buyer_vat_id_reference')->nullable()->after('buyer_vat_id_service');

            // The Control Panel list is a query on this column, and it runs on every
            // visit to that screen. Without the index it is a full scan of the
            // invoice table for the sake of the handful of rows that are pending.
            $table->index('buyer_vat_id_status');
        });

        Schema::create('invoice_vat_id_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->string('vat_id');
            $table->string('status', 16);
            $table->timestamp('checked_at');
            $table->string('service', 32)->nullable();
            $table->string('reference')->nullable();
            // Why there was no answer, when there was none. For the operator, never
            // for the buyer.
            $table->text('failure')->nullable();

            $table->timestamps();

            $table->index(['invoice_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_vat_id_checks');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['buyer_vat_id_status']);
            $table->dropColumn([
                'tax_zone',
                'buyer_vat_id_status',
                'buyer_vat_id_checked_at',
                'buyer_vat_id_service',
                'buyer_vat_id_reference',
            ]);
        });
    }
};
