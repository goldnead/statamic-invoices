<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice, and the counter that hands out its number.
 *
 * The counter is a table rather than a `MAX()+1` query, and that is the whole
 * point of this migration. A number series has to be gapless *and* free of
 * duplicates, and both of those are properties of concurrency, not of
 * arithmetic: two checkouts finishing in the same millisecond both read the
 * same maximum and both write the same number. The prior art this addon
 * replaces did exactly that.
 *
 * So the number comes from a row that is locked while it is incremented. One
 * row per series, and a series is (brand, prefix, period) — which is also why
 * the counter is not simply a column on a brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->nullable();
            // The literal prefix this series counts under, period included:
            // "RE2026-08". Storing the resolved string rather than the pattern
            // means a site that changes its format next year does not
            // retroactively change which series an old invoice belonged to.
            $table->string('series');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            // One row per series, enforced by the database. Without this two
            // processes create two counters and the series forks in silence.
            $table->unique(['brand_id', 'series']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_id')->nullable()->index();

            $table->string('number')->unique();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            // A credit note points at the invoice it reverses. An invoice is
            // never edited — a correction is a second document — so this is the
            // only link that exists between two of them.
            $table->foreignId('reverses_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->timestamp('issued_at');
            $table->string('currency', 3);

            // Everything about the buyer, frozen. Not a reference to a customer
            // record: an invoice that changes when somebody edits their profile
            // is not an invoice.
            $table->string('buyer_name')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('buyer_country', 2)->nullable();
            $table->string('buyer_vat_id')->nullable();
            $table->text('buyer_address')->nullable();

            // And about the seller, for the same reason.
            $table->json('seller')->nullable();

            $table->unsignedInteger('net_cent');
            $table->unsignedInteger('tax_cent');
            $table->unsignedInteger('gross_cent');

            // Why this invoice charges the tax it charges. A sentence, frozen:
            // "Steuerschuldnerschaft des Leistungsempfängers", "§ 19 UStG",
            // "§ 4 Nr. 20a UStG". Read off a rule at the time, kept as text,
            // because the rule may be edited and the invoice may not.
            $table->string('tax_reason')->nullable();
            $table->text('tax_note')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['brand_id', 'issued_at']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            $table->string('product')->nullable();
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_net_cent');
            $table->unsignedInteger('discount_cent')->default(0);
            $table->unsignedInteger('net_cent');
            // Basis points, so 19% is 1900 and 7.5% is not a rounding problem.
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedInteger('tax_cent');
            $table->unsignedInteger('gross_cent');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_counters');
    }
};
