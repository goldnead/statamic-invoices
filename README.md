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

Sending, filing and handing to an accountant all hang off those. This addon writes the document and
stops there.

## What it deliberately does not do

- **Bookkeeping, DATEV export, dunning.** Different job, different software.
- **PDF rendering.** It renders HTML — the same template the preview shows, so the two cannot drift.
  Turning that into a PDF is a decision about infrastructure (a print dialog, a headless browser, a
  queue worker) that an addon should not make for its host.
- **The OSS threshold.** Below €10,000 of annual turnover into other EU countries the seller's own
  rate applies; above it, the recipient's. That is a state over time and needs a turnover figure,
  which is a bookkeeping question rather than a per-line one. The seam is named in the code.
- **VIES lookups.** A VAT ID is checked for shape here, never over the network: a tax calculation
  that depends on somebody else's server is one that fails at checkout when their server is down.
