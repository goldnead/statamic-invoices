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

### As webhooks, through the Webhook Manager

With `goldnead/statamic-webhook-manager` installed, the three events appear there as triggers
("Invoices: invoice issued" / "Rechnungen: Rechnung ausgestellt"). Nothing to switch on: offering a
trigger sends nothing, data leaves only through an outbound webhook somebody creates.
`INVOICES_WEBHOOK_MANAGER=false` (`invoices.webhook_manager.enabled`) hides them. Each is
delivered in the brand of the document, not the brand that happens to be current (an invoice is
written in a payment webhook, where none is).

Every body has the shape the whole suite sends:

```json
{
  "event": "invoices.issued",
  "occurred_at": "2026-09-24T10:12:03+02:00",
  "brand": { "id": 2, "handle": "nordlicht" },
  "subject_type": "invoice",
  "subject_id": 17,
  "invoice": { "...": "see below" }
}
```

| Trigger | Besides the common keys |
|---|---|
| `invoices.issued` | `invoice` |
| `invoices.credit_note_issued` | `credit_note` (same shape as `invoice`), `reverses` (`id`, `number`) |
| `invoices.delivered` | `invoice`, `to` (the address it was mailed to) |

**`invoice`**: `id`, `number`, `kind` (`invoice`, `credit_note`), `payment_id`,
`reverses_invoice_id`, `issued_at`, `currency`, `net_cent`, `tax_cent`, `gross_cent`, `tax_zone`,
`buyer_name`, `buyer_email`, `buyer_country`, `buyer_vat_id`, `items[]` (`product`, `name`,
`quantity`, `unit_net_cent`, `discount_cent`, `net_cent`, `tax_rate_bp`, `tax_cent`, `gross_cent`).

**Never in a body:** the postal address, the seller block, the record of the VAT id check (service,
status, the authority's reference), `meta`, a link to the PDF. Money is always `*_cent` next to
`currency`; times are ISO 8601. `brand` is `null` where brand-context cannot name one.

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

## Selling to businesses

A seller who only sells to businesses has three cases, not twenty-seven. A supply to a business
abroad is taxed where the buyer sits (§ 3a Abs. 2 UStG), so no German VAT is charged in any of
them and what differs is the sentence on the document:

| Zone | Buyer | On the invoice |
|---|---|---|
| `de` | domestic | no VAT, § 19 UStG (or your ordinary rate) |
| `eu-b2b` | EU business with a confirmed VAT ID | no VAT, both VAT IDs, "Steuerschuldnerschaft des Leistungsempfängers" plus the English line (§ 14a Abs. 1 UStG) |
| `third-country-b2b` | business outside the EU | no VAT, "Leistung im Inland nicht steuerbar" / "Not taxable in Germany" |

### The gate

Put the middleware on your own checkout route. It refuses a buyer with no country, no company
name, an EU buyer without a confirmed VAT ID, and a third-country buyer who has not said they are
buying as a business:

```php
Route::post('/checkout', CheckoutController::class)
    ->middleware('invoices.business-buyer');
```

On admission it merges the frozen check into the request as `vat_id_check`. Put that into the
payment's `meta` and the invoice reads it from there — nothing asks the confirmation service
twice, and nothing looks it up again at render time.

`POST /!/invoices/buyer-check` answers the same question for a form while the buyer is typing. It
is a convenience, not the gate: a client can ignore it, and the middleware asks again on the
server.

### When the confirmation service is down

The purchase goes through. The check comes back `pending`, the invoice says "VAT ID provided,
verification pending", and the case appears under **Utilities → USt-IdNr.-Prüfungen** in the
Control Panel. `php artisan invoices:recheck-vat-ids` asks again later and writes what it found
into `invoice_vat_id_checks` — never into the invoice, which does not change. The command exits
non-zero only when a number that was pending now comes back invalid, so it can sit in a schedule
without teaching anybody to ignore it.

The distinction it is built around: `valid: false` is an answer and means invalid; a timeout, a
500, an unreadable body or VIES' own `MS_UNAVAILABLE` inside an HTTP 200 are non-answers and mean
pending. Collapsing the two would tell a business with a correct number that it is wrong.

### Configuration

```php
'tax' => [
    'business_only' => ['enabled' => true, 'require_company' => true],
    'vat_id_check' => [
        'enabled' => true,      // off leaves every check "unchecked", so no EU sale gets through
        'service' => 'vies',
        'timeout' => 8,         // past it the check is pending, not invalid
        'cache_hours' => 168,   // only a *confirmed* number is remembered
    ],
    'merchant_vat_id' => env('INVOICES_SELLER_VAT_ID'),  // makes the enquiry a qualified one
],
```

Bind `Contracts\VatIdVerifier` to your own implementation to use the German BZSt enquiry
(§ 18e UStG) instead of VIES.

## Selling to consumers in other EU countries (OSS)

Once your B2C turnover into other member states passes €10,000 a year, a consumer in Austria pays
Austrian VAT. Switch `tax.oss.destination_taxation` on for that, and either write a zone for every
country you sell into, or let the addon supply the standard rates:

```php
'oss' => [
    'destination_taxation' => true,
    'shipped_rates' => true,          // off by default: nothing changes until you ask
    'shipped_rates_class' => 'standard',
],
```

`Support\EuStandardRates` holds the standard rate of all 27 member states with the date they were
read (`AS_OF`) and their sources. Every line taxed from that table says so in its notes. A zone you
write for a country always beats the table, which is how you correct a rate before the addon
catches up. Standard rates only: a product in a reduced class still needs a zone of its own,
because reduced rates differ by country and by kind of supply. A business with a confirmed VAT ID
still gets reverse charge, and `tax.small_business` still switches everything off. Check the rates
of the countries you actually sell into; this is the addon's reading of published tables, not tax
advice.

Every invoice line keeps the mechanism and the place of supply the rules decided (`tax_mechanism`,
`place_of_supply`), so the tax report can say where the tax is owed a year later.

## Exports for tax and bookkeeping

**Control Panel → Utilities → Invoice export** (permission `access invoice-exports utility`), for
the brand you are looking at:

- **Tax report** for any period: net, tax and gross per treatment (taxable, small business, reverse
  charge, exempt, …), place of supply and rate, credit notes subtracted. Under § 19 the turnover is
  listed with a tax of zero. Tax owed in other member states (the OSS figure) is shown separately.
- **Documents as CSV**, one row per document and rate, credit notes with a minus. Three profiles:
  semicolon with UTF-8 (Excel, DATEV, Lexware Office), semicolon with Windows-1252, comma with a
  decimal point. The column names are fixed German headers, so a saved import mapping keeps working.
- **PDF archive**: every document of the period in one ZIP, built by a queued job
  (`BuildPdfArchive`, timeout 1800 seconds) and stored on `invoices.export.disk` (private, `local`
  by default) until it is downloaded. Set `retry_after` of your queue connection above 1800,
  otherwise a second worker starts the same archive while the first is still rendering. A job the
  queue gives up on shows as failed on the screen.

Text columns that start with `=`, `+`, `-`, `@`, a tab or a carriage return get a leading apostrophe,
so a spreadsheet opens them as text rather than running them as a formula.

**Upgrading to 2.2:** run `php artisan migrate` before the next sale. The writer fills two new
columns on `invoice_items`, and an invoice written before the migration has run fails.

The same from the command line, with any combination of delimiter, encoding and decimal mark:

```bash
php artisan invoices:export csv --month=2026-08 --output=august.csv
php artisan invoices:export report --quarter=2026-Q3
php artisan invoices:export pdf --year=2025 --output=belege-2025.zip   # or --queue
```

Periods: `--from/--to`, `--month`, `--quarter`, `--year`, in the application's time zone. Lines
written before 2.2 carry no mechanism and place of supply; the export derives both from the
document and says how many it derived.

## What it deliberately does not do

- **Bookkeeping, a native DATEV EXTF batch, dunning.** The CSV imports into bookkeeping software;
  posting accounts are that software's job.
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
  which is a bookkeeping question rather than a per-line one. The seam is named in the code, and the
  rates for the other side of it ship with the addon (see OSS above).
  The same threshold matters under **§ 19**: a small business selling to a consumer in another
  member state above it owes that country's VAT unless it uses the EU small business scheme
  (§ 19a UStG). `tax.small_business.eu_threshold_mode` and `eu_scheme` tell the addon which case
  you are in; it warns on the result, it does not decide. None of this is tax advice.
- **Deciding what a VAT ID means without being asked.** The confirmation is a network call, so it
  lives outside the calculation: `TaxRules` still only ever sees a shape and a verdict handed to
  it. See "Selling to businesses" below for the part that does the asking.
