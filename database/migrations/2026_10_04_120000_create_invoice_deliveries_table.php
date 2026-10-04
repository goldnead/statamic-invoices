<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an invoice went to the buyer, or was held back, and nothing else.
 *
 * Beside the invoice and not on it: an invoice refuses every update once it
 * exists, so "it was sent" cannot be a column there. One row per invoice, and
 * the unique index is what makes the delivery happen once: the row is claimed
 * before the mail goes out, and a second attempt (the event arriving twice, a
 * retry after a busy mail server) finds it and stops.
 *
 * A failed attempt removes its claim again, so the next one can send. A held
 * invoice keeps its row: trying again finds the same document, and the case
 * belongs to a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('invoices')->cascadeOnDelete();
            // sending, sent, held
            $table->string('status', 16);
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            // Why it was held, machine-readable (`unexpected_zero_tax`).
            $table->string('reason', 64)->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_deliveries');
    }
};
