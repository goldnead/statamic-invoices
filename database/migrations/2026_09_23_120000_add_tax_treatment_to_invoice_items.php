<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the tax on a line is owed, and under which rule.
 *
 * The rate alone does not say. 20 % is the Austrian standard rate and the
 * French one; 0 % is § 19, reverse charge, an export and an exemption. A tax
 * report per country and a CSV a bookkeeper can post both need the two facts
 * the rules already knew when the line was written, so they are frozen onto
 * the line like the rate is, instead of being re-derived from today's config.
 *
 * Nullable, because every line written before this column existed has neither.
 * The export derives them for those rows and says that it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            // standard, small_business, reverse_charge, intra_community_supply,
            // export, outside_scope, exempt — TaxResult::MECHANISM_*.
            $table->string('tax_mechanism', 32)->nullable()->after('tax_rate_bp');
            // ISO 3166-1 alpha-2. The country whose VAT applies to this line.
            $table->string('place_of_supply', 2)->nullable()->after('tax_mechanism');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['tax_mechanism', 'place_of_supply']);
        });
    }
};
