<?php

namespace Goldnead\Invoices\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an invoice, with its own tax rate.
 *
 * The rate lives here rather than on the invoice because a single order can
 * carry two of them — sheet music at 7% beside a course at 19% — and that is
 * exactly the case a single figure cannot express.
 *
 * @property int $quantity
 * @property int $unit_net_cent
 * @property int $discount_cent
 * @property int $net_cent
 * @property int $tax_rate_bp
 * @property int $tax_cent
 * @property int $gross_cent
 */
class InvoiceItem extends Model
{
    protected $table = 'invoice_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_net_cent' => 'integer',
            'discount_cent' => 'integer',
            'net_cent' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_cent' => 'integer',
            'gross_cent' => 'integer',
        ];
    }

    /** Whether the writer is in the middle of building an invoice. */
    private static bool $writing = false;

    /**
     * Run a callback with line-writing permitted.
     *
     * Deliberately explicit and deliberately narrow: this is the only door, it
     * closes again on the way out, and it closes on an exception too.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whileWriting(callable $callback)
    {
        self::$writing = true;

        try {
            return $callback();
        } finally {
            self::$writing = false;
        }
    }

    protected static function booted(): void
    {
        // Dieselben Riegel wie am Kopf, und zwar aus demselben Grund.
        //
        // Ohne sie war die Rechnung nur dort unveraenderlich, wo jemand
        // hinschaute: einer bestehenden Rechnung liess sich eine erfundene
        // Position anhaengen, eine loeschen oder eine auf einen Cent setzen —
        // der Kopf blieb bei seiner Summe stehen, und die Vorlage druckte
        // beides nebeneinander. Eine verfaelschte Rechnung, die sich als
        // korrekt liest, ist schlimmer als eine offensichtlich falsche.
        static::updating(function () {
            throw new \RuntimeException(
                'A line of an invoice cannot be changed. A correction is a credit note plus a new '
                .'invoice — changing a line here would leave the totals above it untouched.'
            );
        });

        static::deleting(function (InvoiceItem $zeile) {
            // Beim Loeschen der Rechnung selbst kaskadiert die Datenbank, und
            // das ist kein Weg, den ein Aufrufer nehmen kann: der Kopf wirft
            // vorher. Was hier verhindert wird, ist das Loeschen einer
            // einzelnen Zeile unter einem Kopf, der weiterlebt.
            if ($zeile->invoice()->exists()) {
                throw new \RuntimeException(
                    'A line cannot be removed from an invoice. The totals above it would stay as '
                    .'they are, and the document would read as correct while it is not.'
                );
            }
        });

        static::creating(function () {
            // Nachtraeglich angehaengte Zeilen sind der andere halbe Weg zur
            // verfaelschten Rechnung: der Kopf behaelt seine Summe, und die
            // Vorlage druckt beides nebeneinander.
            //
            // Die Erlaubnis ist ausdruecklich statt geraten. Eine Heuristik —
            // "gehoert der Kopf zu diesem Vorgang?" — laesst sich nicht
            // zuverlaessig beantworten, sobald die Relation neu aus der
            // Datenbank kommt, und ein Riegel, der manchmal irrt, ist keiner.
            if (! self::$writing) {
                throw new \RuntimeException(
                    'A line cannot be added to an invoice on its own. Lines are written by '
                    .'InvoiceWriter, in the same transaction as the invoice they belong to.'
                );
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The rate as a person reads it: 1900 basis points are 19%. */
    public function ratePercent(): string
    {
        return rtrim(rtrim(number_format($this->tax_rate_bp / 100, 2, ',', '.'), '0'), ',');
    }
}
