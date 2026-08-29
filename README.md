# Statamic Invoices

An invoice for every payment: a number that is unique and continuous, VAT decided by the buyer's
country, and a document that never changes.

Sits on `goldnead/statamic-payments`. It writes invoices; it does not take money, and it does not do
bookkeeping.

## What it is for

`statamic-payments` can take money in Germany. It cannot, on its own, be *used* in Germany to sell a
digital product to a consumer, because that requires an invoice — with a gapless number, the right
VAT rate, and the sender's details on it. This addon is that missing half.

## Requirements

- PHP 8.2+, Statamic 6, Laravel 12 or 13
- `goldnead/statamic-payments` ^1.9 — earlier versions do not record the buyer's country or the
  discount per line, and neither can be reconstructed afterwards
- `goldnead/statamic-brand-context` ^1.11 — every mail this addon sends leaves through its
  `BrandMailer`, so that each brand's invoice goes out under its own sender identity
- `dompdf/dompdf` ^3.1 — pure PHP, so installing this addon does not also install a Node runtime or
  a system binary. It is a real dependency and not a suggestion, because delivering an invoice
  without a file to attach is not a smaller version of the feature

## Installation

```bash
composer require goldnead/statamic-invoices
php artisan vendor:publish --tag=invoices-config
php artisan migrate
```

Then fill in who is sending the invoices, **before the first one goes out**:

```php
// config/invoices.php
'seller' => [
    'name' => 'Adrian Goldner',
    'address' => "Beispielweg 1\n60311 Frankfurt am Main",
    'vat_id' => 'DE123456789',
    'tax_number' => '01/234/56789',
],
```

A document missing the sender's details is not a valid invoice in Germany — and it cannot be
corrected afterwards, only reversed and reissued.

## The number

German law wants a series that is unique and continuous. Both of those are properties of
**concurrency**, not of arithmetic: two checkouts finishing in the same millisecond both read the
same maximum and both write the same number, and neither notices.

So the number comes from a counter row that is locked while it is incremented, inside the same
transaction that writes the invoice. Two processes queue; nobody gets the same number; nothing is
skipped. A write that fails takes its number back with it.

```php
'number' => [
    'prefix' => 'RE',
    'period' => 'Y-m',   // monthly. 'Y' yearly, '' never restarts
    'pad' => 3,          // RE2026-08-001
    'prefix_per_brand' => [3 => 'CW', 4 => 'HM'],
],
```

**One series per brand.** Two brands sharing a counter each end up with a series full of holes from
their own point of view — and it is each brand that has to answer for its own numbering.

**Which brand an invoice belongs to is read off the payment**, never off the process that writes it.
`statamic-payments` stamps `brand_id` on the row while the buyer is still there; a webhook, a console
run and a follow-up charge have no brand in the environment, and asking the environment there gets
the default brand's answer rather than none. A payment that carries no brand at all still gets its
invoice — refusing a document to somebody who has paid would leave a hole in a series that has to be
gapless — but it says so in the log.

```
php artisan invoices:brand-check
```

Lists invoices whose brand is not their payment's, with number, expected brand and actual brand. It
reports and changes nothing: the number came out of one brand's counter and was counted there, so
what a wrong document needs is a credit note plus a new invoice in the right series, and that is a
decision for a person. A non-zero exit code means there were findings.

Changing the format later renumbers nothing: the resolved series is stored on the counter, so an old
invoice stays in the series it was issued in.

## It does not change

An invoice is immutable once written, and that is enforced on the model rather than agreed by
convention:

```php
$invoice->update(['buyer_name' => 'Someone else']);  // RuntimeException
$invoice->delete();                                   // RuntimeException
```

A correction is a **second document**. `Invoices::creditNoteFor($payment)` writes a credit note that
takes the next number in the series, copies the original's figures, and points at what it reverses.
The tax is copied rather than recalculated — the rate that applied is the rate that applied, and
looking it up again a month later could produce a different one, at which point the two documents
would not cancel out.

On a **full** refund, `statamic-payments` 1.10 fires `PaymentRefunded` and the credit note is written
by itself. A partial refund is not: which lines came back is a question only a person can answer, and
guessing it would put a wrong figure on a tax document.

## Events

| Event | When |
|---|---|
| `InvoiceIssued` | An invoice exists. Fired after the transaction, so a listener always finds the row. |
| `CreditNoteIssued` | An invoice was reversed. Carries both documents, because a credit note read alone says nothing about what it undid. |
| `InvoiceDelivered` | The invoice reached the buyer's mailbox, and at which address. An event rather than a column, because the row refuses every update once it exists. |

Filing it and handing it to an accountant hang off those.

## The PDF

The document is rendered from the same Blade template the preview shows. There is no second layout
to keep in step, which is the one thing a printed invoice cannot afford.

```php
app(\Goldnead\Invoices\Contracts\PdfRenderer::class)->render($invoice);   // PDF bytes
```

**The same invoice always yields the same bytes.** It is read off the stored row and its items —
never recalculated — and the two things a PDF engine normally stamps with the wall clock (creation
date, document id) are derived from the invoice instead. So a copy fetched in nine years is the
document the buyer already has, not a similar one. Change the tax rules, the seller, the price basis
or the number format afterwards: an invoice already written does not move a byte.

The legal texts (`tax.texts`, `tax.legal_bases`) stay yours to change. They are resolved once, when
the invoice is written, and frozen onto it as prose — so an edit today changes what tomorrow's
invoices say and nothing about yesterday's.

The engine is bound to an interface, not hard-wired. A host that already runs a headless browser, or
has a print house with a template of its own, rebinds it:

```php
$this->app->bind(\Goldnead\Invoices\Contracts\PdfRenderer::class, MyRenderer::class);
```

## Sending it to the buyer

On `InvoiceIssued`, so exactly the invoices that were written get sent, once each — no schedule, and
no second place that decides whether a document should exist. A payment that is missing a mandatory
detail still produces **no invoice at all**, and `invoices:pending` says which detail; the sending
path cannot reach around that, because it only ever receives an invoice somebody else wrote.

```php
'delivery' => [
    'enabled' => true,                        // off: the host sends them itself
    'subject' => 'Ihre Rechnung :number',
    'filename' => 'Rechnung-:number.pdf',
],
```

The mail leaves through brand-context's `BrandMailer`, which decides **who it comes from**:

- a brand that declared `settings.mail.from_address` sends under it, over the mailer it named;
- a brand that declared a mail identity and left out the address sends **nothing** — the invoice
  exists, the delivery is refused and logged. Falling back to the host-wide sender would put one
  brand's invoice under another brand's name, which is the failure this is guarding;
- a brand that declared nothing at all falls back to the seller frozen onto *that* invoice
  (`invoices.seller_per_brand`), and only then to `config('mail.from')`.

`php artisan vendor:publish --tag=invoices-views` publishes the covering letter alongside the
document itself.

## What it deliberately does not do

- **Bookkeeping, DATEV export, dunning.** Different job, different software.
- **Storing the PDF.** It is generated on demand and byte-identical every time, so a stored copy
  would be a second source of truth with nothing to add — and a disk to manage, back up and keep
  for ten years.
- **E-invoicing (ZUGFeRD, XRechnung, EN 16931).** A PDF is a picture of an invoice, not a
  structured one. German B2B issuing obligations phase in from 2027; that is a format, a validator
  and a profile decision, and it is its own piece of work rather than a flag on this one.
- **Sending the credit note.** `CreditNoteIssued` fires and nothing listens. Whether a reversal
  should land in the buyer's inbox on its own, or beside the refund the provider already announced,
  is a decision the host has to make.
- **Re-sending by hand.** A delivery that fails is logged with the invoice number and the reason;
  there is no `invoices:send` yet.
- **The OSS threshold.** Below €10,000 of annual turnover into other EU countries the seller's own
  rate applies; above it, the recipient's. That is a state over time and needs a turnover figure,
  which is a bookkeeping question rather than a per-line one. The seam is named in the code.
- **VIES lookups.** A VAT ID is checked for shape here, never over the network: a tax calculation
  that depends on somebody else's server is one that fails at checkout when their server is down.
