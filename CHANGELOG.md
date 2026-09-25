# Changelog

## 2.5.0 — 2026-09-25

### Added

- The invoice mail is a template in `statamic-email-templates` where that addon is installed:
  registered as `invoices-invoice` with occasion, event (`InvoiceIssued`), placeholders and the
  shipped wording. An entry under the slug writes subject and text; the PDF stays attached. Finding
  from ChoirLive: every other mail of the suite could be edited in the CP, this one could not.
- The payment's communication log records the subject that actually went out.

### Upgrading

- New config key `delivery.template` (`INVOICES_MAIL_TEMPLATE`, default `invoices-invoice`). No
  migration. Without an entry, or without email-templates, the built-in mail is sent exactly as before.

## 2.4.0 — 2026-09-25

Findings from the ChoirLive end-to-end check.

### Upgrading

- No migration, no new permission. New config key `display_timezone` (`INVOICES_DISPLAY_TIMEZONE`),
  also on the settings screen. Null falls back to `statamic-payments.display_timezone`, then
  `statamic.system.display_timezone`, then `app.timezone`. **Never change `app.timezone` to fix a
  date on a document**: stored timestamps carry no zone and would all shift.
- Invoices issued before this version render exactly as before (no frozen dates in `meta`).
- `meta` of a new invoice is no longer `null`: it always carries `issued_on`.

### Added

- **Leistungszeitraum (§ 14 Abs. 4 Nr. 6 UStG).** An invoice for a recurring product states the
  service period instead of one service date: from the day paid to the day before the next charge,
  from the payment's subscription (a cycle) or the catalogue `interval` (the first payment). Months
  and years without overflow. Frozen as `meta.service_period`. A credit note carries the period or
  service date of the invoice it reverses.

### Fixed

- **Dates in the shop's calendar.** Invoice date and service date were the UTC day: a purchase at
  00:30 in Berlin was dated the evening before. They are computed in the display zone and frozen as
  `meta.issued_on` when the invoice is written, so a later zone change moves no issued document.
  The pending VAT checks screen and the archive list use the same zone.
- The doubled rhythm in a subscription line ("Chortarif (jährlich) — jährlich") came from the line
  name statamic-payments writes; fixed there in 1.28.0.

## 2.3.0 — 2026-09-24

### Upgrading

- No migration, no new permission.
- **With statamic-webhook-manager 2.10 issued invoices, credit notes and deliveries appear there as
  triggers.** Nothing to do if you want that. To switch it off, set
  `invoices.webhook_manager.enabled` to `false` (env `INVOICES_WEBHOOK_MANAGER`). Without the
  webhook manager nothing changes.

### Added

- **Webhook Manager triggers.** With `goldnead/statamic-webhook-manager` installed,
  `invoices.issued`, `invoices.credit_note_issued` and `invoices.delivered` appear there as
  triggers, labelled in German and English. Each body is a chosen list of fields (number, kind,
  amounts in cent with currency, tax zone, buyer name, email, country and VAT id, the lines), never
  the row: no postal address, no seller block, no VAT check record, no `meta`. Every body carries
  `event`, `occurred_at`, `brand` (`id`, `handle`), `subject_type` and `subject_id`. Delivered in
  the brand of the document. List per trigger in the README.
- Every body carries `event_id` (`sha1` of handle, document and its own time), the same when the
  same moment is told twice; `occurred_at` and the manager's event time are `issued_at`, not the
  time of sending.
- Handed over after the surrounding database transaction commits, never after a rollback.
- A document naming a brand that cannot be set is not delivered at all (logged), instead of going
  out through the current brand's hooks.
- README: order between triggers is not guaranteed (`invoices.delivered` can arrive before
  `invoices.issued`); sort by `occurred_at`, deduplicate by `event_id`.
- Config `webhook_manager.enabled` (`INVOICES_WEBHOOK_MANAGER`, default on).
- The coupling is optional: composer `suggest`, the manager's classes are checked by name before
  anything that implements its interface is loaded, and registration retries at the end of the
  booted queue. A new test boots the addon in its own process with the manager hidden.

## 2.2.0 — 2026-09-23

### Upgrading

- **Run `php artisan migrate` before the next sale.** The writer now fills two new columns on
  `invoice_items` (migration `2026_09_23_120000`); an invoice written before the migration has run
  fails on the missing column.
- **Set the queue connection's `retry_after` above 1800 seconds.** The PDF archive of a period is
  built by a queued job that may run that long; a shorter `retry_after` starts it a second time.
- New config keys, all with defaults: `export.disk`, `export.directory`, `tax.oss.shipped_rates`
  (off), `tax.oss.shipped_rates_class`. A published config file does not have them until you add
  them; the defaults apply meanwhile.
- If the site caches routes (`php artisan optimize`), rebuild the cache so the new export utility
  is reachable.

### Added

**Exports for tax and bookkeeping.** A new Control Panel utility, "Invoice export", and a command,
`invoices:export`, hand a period to whoever does the books: a CSV with one row per document and
rate (credit notes with a minus; semicolon or comma, UTF-8 with or without BOM, or Windows-1252),
a ZIP of the period's PDFs built by a queued job, and a tax report per treatment, place of supply
and rate. Under § 19 the report lists the turnover with no tax. Periods are read in the
application's time zone; one that cannot be read is named on the screen, not replaced in silence.
The CSVs stream, so a year does not run into the request timeout.

**EU standard rates ship with the addon, switched off.** `tax.oss.shipped_rates` lets
`Support\EuStandardRates` (all 27 member states, as of 2026-02-02, with sources) answer for a
consumer in a member state no zone names, once destination taxation applies. A zone you write
still wins; reduced classes still need their own zone; reverse charge and § 19 are unchanged.
Installations that do not switch it on behave exactly as before.

**Each invoice line keeps where its tax is owed.** New nullable columns `tax_mechanism` and
`place_of_supply` on `invoice_items` (migration `2026_09_23_120000`), written by the writer and
copied onto credit notes. Lines from before carry neither; the export derives both and counts them.

### Details of the export

The CSV runs in the order of the invoice date, then the number, and the brand column carries the
brand's name. Every text column a buyer could have typed into (name, e-mail, VAT ID, reference,
booking text) is written with a leading apostrophe when it starts with `=`, `+`, `-`, `@`, a tab or
a carriage return, so a spreadsheet does not run it as a formula; amounts stay numbers. Windows-1252
spells characters outside the code page in Latin ("Łukasz" becomes "Lukasz"), independent of the
process locale. Archives and CSV file names carry the brand, and the screen lists and hands out only
the current brand's archives. A job the queue gives up on, or one older than its timeout, shows as
failed instead of "being built".

## 2.1.2 — 2026-09-09

**A number that was already issued rolled back a fulfilment.** The counter runs per brand and
per period, the number is unique across the whole table, and both of those are wanted. Change a
prefix after invoices exist — something an operator is allowed to do — and the fresh counter
hands out `NL2026-09-001` while that number is already on a document. The database refused, and
the refusal arrived as a `UniqueConstraintViolationException`.

That is not an `InvoiceNotWritten`, and `WriteInvoiceOnPayment` catches only those. So the
exception escaped the listener, rolled back a fulfilment the buyer had already been given, and
had the provider deliver the webhook again — for a problem no retry solves. It is now
`NumberAlreadyTaken`, an `InvoiceNotWritten` like every other reason a person has to decide: the
payment stays fulfilled, the reason lands in the log and in `invoices:pending`, and the document
waits for whoever changed the prefix. The same catch guards the credit note, where the identical
collision was reachable through a refund.

**And one bad row no longer takes the batch with it.** `invoices:pending --write` caught
`InvoiceNotWritten` per row but nothing else, so the collision ended the run at the first hit and
the payments after it were never even tried. Each row now runs inside its own error boundary,
whatever it throws, and the run ends with a non-zero exit code when anything stayed unwritten —
a daily run that leaves invoices missing and exits 0 is the silent kind of failure this command
exists to prevent.

Found on a Stripe test purchase in the playground on 09.09.2026, after a brand's prefix was
changed.

## 2.1.1 — 2026-09-08

**The logo was never in the PDF.** The template put it into the document as inline `<svg>`
markup, and dompdf does not draw an `<svg>` element in HTML. It skips it without a word. Every
invoice generated since the branding landed carried the wordmark and nothing else.

It was invisible from the code: the HTML was right the whole time, and the last review looked
at the HTML. It shows up the moment you render the PDF and look at the page, which is what
found it, on a real invoice from a real shop.

**The test held the defect in place.** It asserted `<svg` was present and that `data:` was
absent, on the grounds that "the PDF carries the SVG as markup". Both green, both wrong. The
rule the test exists for is that nothing is fetched over the network, and a `data:` URI is the
strictest form of that rule rather than a breach of it: the bytes are in the document.

The logo now travels as `data:image/svg+xml;base64`. Measured rather than assumed, by putting
three forms through dompdf and looking at the result: base64 renders, a filesystem path in
`src` renders too but only with `chroot` set, and a raw `;utf8,` URI produces an empty box.
The base64 form is the only one that serves both consumers of this template, because the
on-screen preview cannot read a filesystem path.

Nothing else changes. An invoice rendered before this release still renders identically apart
from the logo, and no stored data is touched.

## 2.1.0 — 2026-09-07

**A settings page in the Control Panel.** Seller identity, number series, the § 19 switch, the
tax switches and the sentences on the document were only reachable through `.env` so far. A
`.env` holds one value per key; a multi-brand host has two addresses, two tax numbers and
possibly two answers to § 19 UStG, the German small-business rule. What is printed on an invoice
is no small matter: without the details of the supplying business the document is not an invoice
under § 14 UStG, and those details are frozen when it is issued.

The page is not built here. The addon only registers its field list (`Support\Settings`,
`Goldnead\BrandContext\Contracts\ProvidesSettings`) with the `SettingsRegistry`; screen, form,
validation, storage, brands and routes come from `statamic-brand-context` (hence `^1.12`). Only
deviations are stored — whatever nobody changes keeps following `config/invoices.php`, and a
package update moves the defaults as it did before.

- New permission `manage invoices settings`, guarding the section.
- Deliberately not on the page: `tax.zones`, `tax.product_classes`, `tax.exemptions`,
  `number.prefix_per_brand` and `seller_per_brand` (nested maps — and the two `*_per_brand` are
  redundant anyway, because the page works per brand); `tax.legal_bases` (they belong to the
  rules that produce them); `number.period` and `number.separator`;
  `tax.prices_include_tax` (three-valued, and the fieldtypes carry two — empty means "not
  answered yet" here, which is something other than net); `pdf.paper`. What is missing is named
  on the page instead of being left out silently.
- No key of this addon is read while the routes are registered, so none is dropped for that
  reason. A test holds that in place.

## 2.0.0 — 2026-09-05

**Two behavioural changes that affect an existing installation — hence the major version.** Both
are described below, together with the way back. Anyone with EU B2B customers should read the
section "a merely well-formed number no longer exempts" first and then run
`php artisan invoices:pending-invoices` once: it says which paid payment was left without a
document. Tagged as a minor, this change would have announced itself as a log line, and only
after the money had moved.

Three tax zones, a real VAT ID verification and a gate in front of the checkout. The occasion is
selling the addon suite abroad: to businesses, mostly outside Germany, through our own chain.
That exact constellation hit the places where the addon could do less than a cross-border
invoice needs.

### The VAT ID is verified, not merely looked at

So far the number was held against a pattern, and the invoice honestly stated that only its shape
had been checked. `Verification\ViesVerifier` now asks the EU's confirmation service. If the
seller's own number is in `tax.merchant_vat_id`, the request is a qualified one, and the answer
carries a reference number that can be quoted years later.

The result is **frozen on the invoice**: verdict, timestamp, service and reference in four new
columns. Nothing is looked up while rendering — today's answer is not the one the seller relied
on back then.

**An outage is not a verdict.** Timeout, HTTP 500, broken body, or VIES' own `MS_UNAVAILABLE`
inside a 200 — all of it ends as `pending`, never as `invalid`. The purchase goes through, the
invoice says "USt-IdNr. angegeben, Bestätigung ausstehend / VAT ID provided, verification
pending", and the follow-up sits in the new utility in the Control Panel. That keeps the rule
from 2026-08-25 intact: an invoice does not fall over with somebody else's server.
`invoices:recheck-vat-ids` asks again later and writes the result into a table of its own — never
into the invoice, which does not change.

### Three zones instead of twenty-seven country rates

`Support\TaxZone`: `de`, `eu-b2b`, `third-country-b2b`. A seller who only sells to businesses
needs no more than that — a service supplied to a business abroad is taxed at the recipient
(§ 3a Abs. 2 UStG), no German tax arises in any of the three cases, and what differs is the
sentence on the document. The country rates therefore stay out.

### Behavioural change: § 19 no longer masks cross-border B2B

So far the small-business branch (§ 19 UStG) answered the cross-border B2B case as well, with
"keine Umsatzsteuer nach § 19 UStG" and a warning. That is the wrong mandatory statement: § 19 is
a domestic rule, and for a service supplied to a business abroad the place of supply is at the
recipient. There § 14a Abs. 1 UStG wants "Steuerschuldnerschaft des Leistungsempfängers" (reverse
charge) and both VAT IDs on the document. Derivation:
`TASKS/suite-steuer-selbsteinschaetzung-2026-09-05.md`, question (b).

The new path only applies **where somebody actually established that the buyer is a business**: a
confirmed (or, after an outage, pending) VAT ID inside the EU, a declaration by the buyer outside
it. A merely well-formed number changes nothing — the old path keeps answering as before.

The sentences for the two foreign zones are now printed in both languages: the prescribed German
wording, followed by the English one from `tax.texts_en`.

### Behavioural change: a merely well-formed number no longer exempts

So far it was enough for a VAT ID to match a pattern for an EU supply to be treated as exempt —
with the prescribed § 14a wording on the document and a note in the internal remarks that only
the shape had been checked. That asserts a verification that never took place, and the document
looks entirely unremarkable while it does so.

In that case the result is now no sentence at all but `undetermined` with the code
`vat_id_unconfirmed` — the invoice is not written, and the case ends up where this addon puts
every unresolved case: with a person. Anyone who deliberately wants to live with a pure format
check sets `tax.vat_id_check.enabled` to `false`; everything then behaves as before, including
the old note. The decision is explicit rather than preset.

The affected unit tests now pass the verification state visibly (`VatIdStatus::Valid`). That they
used to leave it out and were green regardless was precisely the problem.

### The gate in front of the checkout

`Support\BuyerAdmission` answers whether a buyer may buy and in which zone. Turned away are:
no country, no company name, EU without a confirmed VAT ID, third country without a confirmation
of business use. Two surfaces:

- The middleware `invoices.business-buyer`, which a host puts on its own checkout route. It is
  the enforcement and writes the frozen result into the request itself, so a client cannot slip a
  "confirmed" underneath.
- `POST /!/invoices/buyer-check`, so the form already knows the answer while the buyer is typing,
  instead of after the payment has started.

An outage of the verification service turns nobody away.

### Control Panel

New utility "VAT ID checks": the invoices whose number could not be confirmed at the time of
purchase, together with what a later enquiry produced. The screen shows and does not act — what
follows from a number that is invalid a week later is a decision, and § 6a Abs. 4 UStG protects
reliance on the information given on the day of the purchase.

The list contains not only the pending checks but also the documents that carry a number **nobody
ever asked about**: older invoices, or a payment that reached the writer past the checkout.
Otherwise that would be a class of invoices no evaluation can count, because none of them knows
about it. The screen and `invoices:recheck-vat-ids` read the same definition
(`Invoice::scopeAwaitingVatIdConfirmation`), so the list shows nothing the command never touches,
and the other way round.

`tax.business_only.enabled` now does what the comment beside it always promised: on `false` the
gate stops turning consumers away. Before that the gate only read `require_company`, and the
switch had no effect without saying so.

### The document names the company

The gate requires a company name, and so far that name got no further than the gate: the invoice
stated who had filled in the form. § 14 Abs. 4 Nr. 1 UStG wants the recipient of the supply named,
and on a business purchase that is the company — otherwise the buyer's bookkeeping cannot post the
document against their business, which was the reason for giving the VAT ID in the first place.
The person is not lost, they sit on the document as `meta.buyer_contact`.

### The question is only asked where the answer decides something

Domestic and third-country numbers no longer go to VIES. For a German buyer the confirmation
decides nothing in tax terms, but costs something real in an outage: the invoice would carry
`pending` and would sit on the check list permanently. And VIES only knows EU numbers — sending a
US tax number there means getting an answer to a question nobody asked. Both numbers still appear
on the document, now with the remark "USt-IdNr. angegeben, nicht bestätigt" (VAT ID given, not
confirmed).

### New configuration

`tax.vat_id_check` (`enabled`, `service`, `timeout`, `cache_hours`), `tax.business_only`
(`enabled`, `require_company`), `tax.texts_en`. The migration
`2026_09_05_090000_add_vat_id_verification_to_invoices` adds the four columns plus `tax_zone` to
`invoices`, and the table `invoice_vat_id_checks`.

### Tidying at the edge

Routes are now registered from `boot()` instead of from `bootAddon()`. The latter runs from a
`Statamic::booted()` callback, and a route registered there sits in the collection without ever
taking effect — it only worked because something else in an app registered first.

## 1.3.1 — 2026-09-05

No behaviour changed. This addon writes the invoices the suite is sold with, and it was the only
one in the family without a licence file and without CI.

### Licence

`LICENSE.md` now ships with it, word for word the same as in statamic-payments and the remaining
siblings (proprietary licence, copyright Adrian Goldner). `composer.json` already said
`proprietary`; the file was missing.

### CI

`.github/workflows/tests.yml` following the family's pattern: a matrix of PHP 8.2 to 8.4 ×
Laravel ^12 and ^13 × prefer-lowest and prefer-stable (PHP 8.2 with Laravel 13 excluded, which
requires ^8.3), plus Pint as a check, addon-lint as a gate and PHPStan. The test run is
`vendor/bin/pest`, not phpunit: the tax rules are written in Pest syntax, and Pest only carries
PHPUnit classes along when it is the runner itself. No `dist` job, there is no Control Panel
bundle.

### Tooling

- **Larastan** in `require-dev`, `phpstan.neon` at level 5 over `src/`. The Insights metrics
  extend a class from an addon that is only a `suggest`; the stand-ins under `tests/Fakes/` are
  therefore scanned, not analysed. The baseline carries two entries, both `view-string` on
  `invoices::…` views: Larastan checks whether the view exists and does not know the addon
  namespace in the analysis context. `InvoiceCounter` now has the `@property` lines PHPStan
  needed.
- **Pint** excludes `tests/Fakes/insights-contracts.php`. The file is a byte-for-byte copy of the
  signatures from statamic-insights; running a formatter over it would destroy exactly the
  property that makes it worth having.
- **`.gitattributes`** with `export-ignore` for tests, CI and tool configuration; a site that
  installs the addon no longer downloads them.
- **`addon-lint.json`** waives two rules with a reason: `release.readme` (the rule looks for
  heading words such as "Usage"; the README explains usage under "The number", "The PDF",
  "Sending it to the buyer") and `testing.addon-testcase` (the test base is deliberately built by
  hand, see `tests/TestCase.php`; moving it to `AddonTestCase` is a step of its own). Lint score
  79 → 100.

### Tests against statamic-payments 1.17

Two tests silently assumed payments 1.11, the state of the local `vendor/`. Against 1.17.1, which
is what `^1.14` resolves to today, they failed:

- `InsightsMetricsTest` compared the **entire** metric registry against its own four entries.
  payments has registered metrics of its own since 1.14. Only the `invoices.` handles are
  compared now.
- `TheBrandComesFromThePaymentTest` tested an installation whose `payments` table has no
  `brand_id` column yet. `^1.14` has ruled that state out since 048fde2 (the column arrived with
  1.13), and payments 1.17 sets it on insert itself; the test only proved that you cannot write
  into a dropped column. Removed, with a note at the place.

## 1.3.0 — 2026-09-02

### The invoice mail appears in the payment's communication log

`InvoiceDelivery::send()` records a delivered invoice through `PaymentLog::mail($paymentId,
'invoice', $to, $subject, 'sent', ['invoice' => $number])` in statamic-payments'
`payment_communications` (from its 1.16 onwards, on the payment's detail page). Via
`class_exists` on the facade; an older payments without it stays untouched, and a failure while
writing there never breaks the delivery.

### Added: § 19 warns for a consumer elsewhere in the EU

With the small-business rule (§ 19 UStG) active, `TaxRules` set everything to 0 % and warned only
in the B2B case, that is, when a VAT ID was present. For a consumer in another member state
nothing came out — and that is the case where tax can fall due in the buyer's country despite
§ 19: for digital services the place of supply is at the buyer (§ 3a Abs. 5 UStG, for goods
§ 3c), as soon as EU-wide B2C turnover exceeds the €10,000 threshold. The German exemption then
only reaches there through the EU small-business scheme (§ 19a UStG, since 2025-01-01, "EX"
number); without it, VAT of the buyer's country is due (OSS). Below the threshold the place of
supply stays in Germany and § 19 applies as before.

Two new keys under `tax.small_business`, neither of them computed, because both are facts about
the year and not about the line: `eu_threshold_mode` (`'below'`, the default, or `'above'`) and
`eu_scheme` (`false`, the default). Above the threshold without the EU scheme the result carries
a warning in the `notes` field, the same path as in the B2B case. With the EU scheme, `tax_reason`
names the § 19a exemption (`texts.small_business_eu`, `legal_bases.small_business_eu`) instead of
§ 19. An unknown value for `eu_threshold_mode` throws, like an unknown key.

This is the reading of the law the addon works with, not tax advice; it has not been reviewed by
a tax adviser yet.

### Fixed: the tax calculator's notes reached nobody

`TaxResult::notes` carried them from the start, and the `InvoiceWriter` dropped them: it took
over the reason and the mechanism and nothing else. The B2B warning under § 19 was therefore on
no path a human sees, ever since 1.0.0. The writer now logs each note as
`Log::warning('invoices: tax note', ['payment' => …, 'product' => …, 'note' => …])` before it
decides whether to write; stores them on the invoice under `meta.tax_notes` (a list of `product`
and `note`), from where the credit note takes them along; and `invoices:pending` prints them per
payment underneath the table, with and without `--write`. New for that:
`InvoiceWriter::taxNotesFor()`. They do not appear on the document; they are for the audit, not
for the buyer.

## 1.2.1 — 2026-08-29

### Raised: `statamic-payments ^1.14`

The invoice's brand now comes from the payment, and the `brand_id` column has only existed there
since 1.14. With an older version the code would run through — it would fall back to the default
brand, with a log warning — but that is exactly the defect 1.2.0 fixes. A requirement that allows
the fixed state to return would be no requirement at all.

A patch version of its own, because 1.2.0 had already been published at that point. A published
tag is not moved.

## 1.2.0 — 2026-08-29

### Added: four figures in Insights

Documents issued, net, gross and VAT, splittable by kind, buyer country and tax rate. A credit
note subtracts everywhere, so every money figure sums with the correct sign — a reversal can push
a tile below zero, and that is as it should be.

### Fixed: the tile showed other brands' turnover

With a brand selected, the *Invoices* group showed four documents belonging to **three other
brands**. Not merely a wrong figure: one customer's turnover on another customer's screen. The
rule now lives once, in `TableMetric::brandScoped()`; all that is named here is the column.

Two queries do not reach the table through the central path and carry the brand explicitly. The
second of them is the more unpleasant one: `filterOptions()` read the currency list across all
brands, and the most used currency went from there into the `where` of every tile. A brand that
invoices only in francs had, on an otherwise euro-based installation, every figure filtered to a
currency it never uses, and read 0. **A brand with documents appeared as one without** — the leak
from the other side.

On top of that, the time window of the tax-rate split was inclusive and lost the last second of
the period on a millisecond column.


### Fixed — the invoice's brand was the process's, not the purchase's

`brandIdFor()` asked the ambient context, and the branch that was meant to safeguard that was
dead: `currentId()` has the return type `int` and falls back to the default brand, a `null` never
existed. In multi-brand operation with no context set — a webhook, a console run, a recurring
charge, that is, exactly where invoices come into being — the invoice therefore silently got the
default brand's number series, and since the PDF commit its sender address as well. No error, no
log, just a wrong result on a document that cannot be changed afterwards.

The brand now comes from the payment. `statamic-payments` stamps `brand_id` when it creates the
row, in the request the buyer was actually in, and a recurring charge inherits the brand of the
row it belongs to. The comment above the method claimed the opposite ("a brand is not recoverable
from the payment") — it was true until that column existed.

**Nothing is refused because of this.** A payment with `brand_id = 0` in multi-brand operation
belongs to no brand (legacy data without a backfill, or a checkout while brand-context could not
answer) and gets its invoice as before — whoever paid is entitled to the document, and a later
run could not make up for it without leaving a hole in a gapless series. All that is new is that
this fallback is logged. Single-brand operation stays at `0`, and an older installation of
`statamic-payments` without the column runs into no SQL error.

`Exceptions\BrandUnknown` is therefore gone without replacement. It was never thrown, and an
exception nobody throws describes a behaviour that does not exist.

### Added — `invoices:brand-check` measures what was filed wrongly before

The comparison of `invoices.brand_id` against `payments.brand_id`: the deviations are exactly the
invoices that came into being on the silent path. The command names the number, the expected and
the actual brand and **rewrites nothing** — the number comes from one brand's gapless counter and
was counted there; moving it across would leave a hole in one series and a foreign body in the
other. If the column in `payments` is missing, it says so instead of letting an empty list look
like an all-clear.

### Added — the invoice becomes a PDF and goes to the buyer

Up to here the invoice existed only as HTML, and that was a deliberate omission: a print engine is
an infrastructure decision, and an addon does not make that for its host. The way out is a
contract instead of a fixed class — `Contracts\PdfRenderer` sits in the container, `DompdfRenderer`
ships with it. **dompdf**, because it is pure PHP: any other candidate (Browsershot,
wkhtmltopdf) would have installed a Node runtime or a system binary along with the addon.

It is generated from the same Blade template the preview already shows. A printed tax document
cannot afford two layouts that are free to drift apart.

**Generating twice produces the same file, byte for byte.** Otherwise dompdf stamps the wall clock
(`CreationDate`, `ModDate`) and a randomly drawn document ID into every file; both are now derived
from the invoice itself. Without that, fetching it again nine years from now would be a different
document from the one the buyer has — visible to nobody until it is a question during an audit.

Delivery happens on `InvoiceIssued`, not by cron: that way exactly what was written goes out, once.
If a mandatory detail is missing, **no** invoice comes into being, as before — the delivery path
only ever sees finished documents and cannot get past that check.

Sending runs through the `BrandMailer` from brand-context, so that the sender identity belongs to
the brand. A brand that states a mail identity and omits the address sends **nothing at all**; a
brand that has stated nothing falls back to the seller frozen on *this* invoice, not to the
host-wide sender — in multi-brand operation that one belongs to a different brand.

### Changed — brand-context is now a real dependency

Previously a `suggest`. Anyone who sends invoices needs the `BrandMailer`; a delivery that,
depending on the installation, silently goes out under somebody else's name is not a smaller
version of the feature. The remaining addons in the family that send mail have handled it the same
way since August.

### Fixed — `invoices:pending` aborted on a missing mandatory detail

The loop caught only `RateUndetermined`. A `DetailsMissing` flew all the way up, the run ended
with a stack trace, and the remaining payments were not even looked at — in a command whose only
job is to say what the matter is.

### Changed — the template does without flexbox

The header, the key figures and the footer were on `display: flex`, and no pure-PHP print engine
knows that: the sender would have ended up below the recipient instead of beside them. Tables and
margins now, which browsers and print understand alike. The table headings are on
`font-weight: 700` instead of 600 — the print engine does not find an intermediate weight and
falls back to its serif face.

## 1.1.0 — 2026-08-26

### Fixed — sold through an offer meant: no invoice

`product()` read `config('statamic-payments.products')` directly and never asked the `Catalogue` —
that is, exactly the seam through which `statamic-offers` hangs its offers under the `offer:`
prefix. For every payment that went through an offer, `isDigital()` threw `ProductIncomplete`, and
**no document at all** came into being. `statamic-funnels` uses an offer for every paid step, so
the advertised chain broke at its last link.

On top of that, two neighbouring defects the same test uncovered:

- **The tax class is configured per product handle**, and an offer has one of its own. Without the
  new `taxHandle()`, an offer for a reduced-rate product would have fallen silently to the default
  class — wrong rate, right appearance, unchangeable document.
- **The line item's name was the raw handle.** A payment without line items printed `kurs` instead
  of "Chorleitungskurs" and `offer:fruehling-upsell` instead of the name the buyer had read.
  Pre-existing; only visible once an offer made the handle ugly enough.

### Changed — `prices_include_tax` no longer has a default

Out of the box it was `false`, so stored prices counted as net. For an addon whose target group in
Germany sells to consumers, that is the wrong assumption: under the Preisangabenverordnung (the
German price indication regulation) the displayed price is the final price including VAT. Whoever
enters €19 means €19 gross — the invoice stated €22.61 for a payment of €19.

There is no assumption now. The first invoice refuses with `PriceBasisUndecided` until somebody
has answered once per installation. Where money is concerned, a refusal with a reason is better
than a plausible wrong figure.

The question is only asked where the answer decides something: under § 19, under an exemption and
at a rate of 0, net and gross are the same, and there it stays silent.

### Added — the invoice has to match the money

`DoesNotMatchThePayment`: a document's total has to be the amount that was actually collected.
That is the only external check an invoice has at all — everything else about it is consistent by
construction, because the same code that adds up the lines is the code that writes them. A wrong
rate produces a wrong invoice that looks exactly like a correct one; the bank statement is the only
witness, and so far nobody has asked it.

It catches the whole family at once: a price basis standing the wrong way round, a rate where none
belongs, a discount that loses a cent while being split, line items that do not sum to the payment.

**What it showed in passing:** `prices_include_tax => false` cannot be correct today for an invoice
derived from a payment, because nobody in this family adds tax at the checkout. The option stays —
a host may do that one day — but a misconfigured installation now finds out at the first invoice
instead of at the next audit.

## 1.0.0 — 2026-08-25

The first release. A review before it went out proved six things wrong; each of them is fixed and
pinned by a test in `tests/Feature/TheCriticsFindingsTest.php`:

- **One payment could get two invoices.** Demonstrated on MySQL: five concurrent calls wrote
  `RE2026-08-001` and `-002` for the same €244. The duplicate check sat before the transaction, and
  the unique index it relied on did not exist — `constrained()` creates a foreign key, not a unique
  one. There is now a real `unique(payment_id, kind)`, which also allows exactly one credit note.
- **Under concurrency most callers lost their invoice.** Three of five MySQL processes hit a
  deadlock, and the transaction ran with a single attempt. Three now.
- **Only the invoice head was immutable.** `InvoiceItem` had no guard at all: a line could be added,
  changed or deleted under a head that kept its totals, and the template printed both. That is a
  falsified invoice that reads as correct.
- **The printed line did not add up at quantity > 1.** 3 × €10 gross printed "3 × €8.40" above a net
  of €25.21. Rounding now goes the other way and the remainder lands in the discount, where it is a
  stated number.
- **`digital` was guessed.** `?? true` made a vinyl record a digital service and printed the wrong
  mandatory note. It refuses now, like a missing country.
- **The brand came from the request.** `currentId()` falls back to the default brand, and nothing is
  current in a webhook — so a second brand's invoice landed silently in the first brand's series.

One more found while wiring it into a demo with five brands, and it is the same class of mistake as
the six above: **two brands shared a prefix and handed out the same number.** The counter is per
brand, the number is globally unique, and `RE` for both means the second brand's invoice dies on the
index — on an order somebody already paid for. A multi-brand installation now has to give each brand
its own prefix, and says so instead of colliding. Deriving one from the brand handle would have been
a guess that silently renumbers an installation the day it adds a brand.

Two mandatory details are checked before writing, because an invoice cannot be corrected: the
sender's own details always, and above €250 the recipient's name and address (§ 14 UStG). Below that
line § 33 UStDV allows a Kleinbetragsrechnung, which is the ordinary case for a digital product.

The totals are broken down **per tax rate**, as § 14 Abs. 4 Nr. 8 requires — a single net line above
an invoice carrying 19 % and 7 % does not satisfy it, and that is the normal case here. An invoice for every payment: a number that is unique and continuous, VAT decided
by the buyer's country, and a document that never changes.

### The number

Taken from a counter row that is **locked while it is incremented**, inside the same transaction
that writes the invoice — not from `MAX() + 1`. German law wants a series that is unique *and*
gapless, and both of those are properties of concurrency rather than arithmetic: two checkouts
finishing in the same millisecond both read the same maximum and both write the same number, and
neither notices. The prior art this replaces did exactly that.

One series per brand, restarting on a configurable period. Changing the format later renumbers
nothing: the resolved series is stored on the counter.

### It does not change

`update()` and `delete()` throw on the model. A correction is a **second document** — a credit note
that takes the next number, copies the original's figures and points at what it reverses. The tax is
copied rather than recalculated: the rate that applied is the rate that applied, and looking it up
again a month later could produce a different one, at which point the two documents would not cancel
out.

On a full refund the credit note is written by itself. A partial refund is not: which lines came
back is a question only a person can answer, and guessing it would put a wrong figure on a tax
document.

### No guessed rate

If no rule matches, **no invoice is written** and `invoices:pending` says which payments are waiting
and why. The alternative — falling back to the standard rate — is the failure this addon exists to
avoid: a wrong rate on a tax document looks like an answer. It is wrong quietly, it is signed, and
it is handed to a customer.

That applies to a missing country too. Payments taken before `statamic-payments` 1.9 have none, and
those get no invoice rather than one at the seller's own rate.

### What it deliberately does not do

- **The OSS threshold.** Below €10,000 of annual turnover into other EU countries the seller's own
  rate applies, above it the recipient's. That is a state over time and needs a turnover figure —
  a bookkeeping question, not a per-line one. There is a switch (`tax.oss.destination_taxation`) and
  a named seam; what is missing is written down in the code.
- **VIES lookups.** A VAT ID is checked for shape, never over the network: a tax calculation that
  depends on somebody else's server is one that fails at checkout when their server is down.
- **PDF rendering.** It renders HTML — the same template the preview shows, so the two cannot drift.
- **Bookkeeping, DATEV, dunning.**

### Requires

`goldnead/statamic-payments` ^1.9 — earlier versions record neither the buyer's country nor the
discount per line, and neither can be reconstructed afterwards.
