<?php

namespace Goldnead\Invoices\Exceptions;

/**
 * Nobody has said whether the catalogue's prices already contain tax.
 *
 * This is the one question about money that cannot be answered by looking. A
 * product priced at 1900 is either €19.00 gross or €19.00 net plus tax, and the
 * two produce different invoices for the same payment: €19.00 with €3.03 of tax
 * inside it, or €22.61 with €3.61 on top. Both look correct. Only one matches
 * the money that actually arrived.
 *
 * The default used to be `false` — prices are net. For a package whose audience
 * sells in Germany that is the wrong guess: under the Preisangabenverordnung
 * the price a consumer is shown is the final price including VAT, so somebody
 * writing 19 € into a product catalogue means 19 € gross, and the buyer paid
 * exactly that. An invoice adding tax on top shows €22.61 against a payment of
 * €19.00.
 *
 * What makes it worth an exception rather than a better default: **nothing
 * contradicts it.** The invoice is internally consistent, every line adds up,
 * and the only thing that does not match is the bank statement. It is found by
 * a tax adviser or by a customer, not by the person running the site.
 *
 * So there is no default any more. The first invoice refuses until somebody has
 * decided, once, per installation. A refusal with a reason beats a plausible
 * wrong number, and this is a decision, not a guess anyone can make for the
 * operator.
 */
class PriceBasisUndecided extends InvoiceNotWritten
{
    public function __construct()
    {
        parent::__construct(
            'invoices.tax.prices_include_tax is not set, so it is unknown whether catalogue '
            .'prices already contain tax. No invoice was written: the same payment produces '
            .'two different, equally plausible documents depending on the answer, and only one '
            .'of them matches the money that arrived. Set it to true if your prices are gross '
            .'(the usual answer for a German consumer shop, where the displayed price is the '
            .'final price including VAT), or false if they are net.'
        );
    }
}
