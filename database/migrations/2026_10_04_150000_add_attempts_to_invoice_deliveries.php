<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A failed send keeps its row now, as `failed`, so `invoices:retry` can find it:
 * how many sends were tried and what the last one died of. Rows that exist
 * today (sent, held, sending) start at 0 and are never retried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_deliveries', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('reason');
            $table->text('last_error')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_deliveries', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'last_error']);
        });
    }
};
