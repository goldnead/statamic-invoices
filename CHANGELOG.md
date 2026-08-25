# Changelog

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
