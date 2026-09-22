# Conformance vectors

Cases this engine holds itself to, as plain JSON with no PHP in it. Each vector is a
determination — a supply in, an answer out — and a sentence saying what it pins.

```bash
vendor/bin/pest tests/Feature/ConformanceTest.php
```

## Independent references against a real release

The fixture corpus below checks engine behaviour. A separate
[reference corpus](reference/README.md) checks 41 dated results against public
tax-authority references, using a real
downloaded register and the public application-container bindings:

```bash
composer test:reference
```

See the [data/engine validation matrix](validation-matrix.md) for ownership,
remaining data in PHP, and the difference between fixture, adapter and independent
integration evidence.

The reference group also runs the dated-rule, rounding and delivery regression
against a pinned release. Remaining publication work is collected in
[cadastre feedback](cadastre-feedback.md). The companion
[consumer-contract proposal](cadastre-consumer-contract.md) distinguishes existing
schema fields from proposed predicates, comparison operators and rounding groups,
with input/output acceptance cases. Those draft cases are not counted as executed
conformance tests.

## Why this exists

**One engine, several consumers.** The library, the HTTP API and any embedded
integration are three ways into the same determination, and three things that drift
apart quietly. Each keeps passing its own tests while disagreeing with the others,
and nobody finds out until a customer does. A shared corpus is the only thing that
makes disagreement visible.

**The cases are inspectable and reusable.** Each one records the inputs and expected
outcome so consumers can reproduce it through their own integration and review why
that answer was selected.

## What a vector looks like

```json
{
  "id": "b2b-unvalidated-id-is-charged",
  "pins": "THE FAIL-SAFE DIRECTION. A business customer whose VAT number was NOT
           conclusively validated is charged, not zero-rated.",
  "query":  { "amount": "1000.00", "currency": "EUR", "place": "FR", … },
  "expect": { "treatmentNot": "reverse_charge", "taxGreaterThanZero": true }
}
```

`pins` is not a comment. A corpus meant for someone else is worth nothing as a list
of opaque numbers, and a test enforces that every vector carries a real sentence.

Expectations are deliberately mixed in strictness. `ratePercentage` pins an exact
figure where the law fixes one; `treatmentNot` and `taxGreaterThanZero` pin only the
*direction* where the exact figure is not the point — a vector that over-specifies
breaks on a change that was never what it was guarding.

## Vectors pin the fixture, not the mirror

They run against the register fixture defined in `tests/Fixtures/SuiteRegister.php`,
never the live published register. A rate that changes in the world must not silently change what a
vector asserts — that would make this a mirror of today's data instead of a
description of behaviour. When a rate genuinely changes, the fixture is updated
deliberately and the diff is reviewable.

## Two regimes, two answers to the same question

`vectors/eu-vat.json` and `vectors/us-sales-tax.json`. The corpus carries both
because the same input gets opposite answers, and neither is a special case:

> A seller with no registration where the customer is. In the **US** that is no
> nexus, no authority to collect, and charging would be collecting tax nobody is
> owed. In the **EU** it is a distance seller who is obliged to collect through OSS
> and simply has not registered — non-compliance, not relief.

Both are in the corpus, side by side, for exactly that reason. Reading one as the
other is the mistake that produced two of the first wrong vectors here.

New files under `vectors/` are picked up automatically; a corpus declares its
`regime` and the runner builds the right one.

## Two shapes, and why

Vectors come as single supplies and as documents. A corpus built from one consumer's
cases quietly designs the engine around that consumer — subscriptions have prorations
and dunning, carts have shipping and returns, and whichever arrives first sets the
shape the other has to live in. Both are here from the start for that reason.

The document shape earned it immediately. It is where delivery lives, and delivery is
where the engine turned out to have nothing at all.

## What the corpus caught on its first run

Two of the first ten vectors were wrong, and both were wrong the same way — a
foreign intuition applied to EU VAT:

- **The broad grocery class needs explicit fixture expectations.** The fixture
  assigns Hungary 27% and France 5.5% to exercise both standard and reduced paths.
  These invented expectations do not establish the legal rate for every food
  product in either country; real classifications need a sourced reference.
- **An empty registration list was read as "charge nothing."** That is US thinking:
  there, no nexus means you must not collect. In the EU a distance seller is obliged
  to collect through OSS, and relief must be *affirmatively asserted* — silence is
  not relief, and not being registered is non-compliance rather than an exemption.

Neither was an engine defect. They exposed assumptions in the fixture vectors;
independent references are needed to establish real-world correctness.

Then the order shape found three more, and these were real:

- **Delivery had no representation whatsoever.** Article 78(b) makes a delivery charge
  part of the taxable amount of what it delivers, so postage on a cart of books is
  charged at the books' rate. There was no way to say a line was delivery, so a caller
  had to pick a class for it and got 20% where 5.5% was due — on the single most
  common line in e-commerce.
- **The tax was rounded once per line.** Three lines at 5.5% sharing a 10.00 charge
  gave 3.33, 3.33 and 3.34, each taxed to 0.18, totalling 0.54 against the 0.55 that
  10.00 at 5.5% actually is. Apportioning per RATE rather than per line rounds once.
- **The US vectors assumed a state code buys precision.** Two of them asserted an
  authoritative, broken-down answer from `US-FL` and `US-CA` alone. A bare state has
  no county to resolve, and the engine correctly returns a *derived* rate carrying
  `NoLocalResolution` and no breakdown at all — precision comes from what you ask,
  not from which state you are in. That is now what those two vectors pin, and it is
  a better thing to pin than what I meant to write.
- **The order runner was not pinned to the fixture.** It resolved the calculator from
  the container, which reached for the default rate source, so an order vector
  asserted nothing about the data this corpus claims to pin. The first one passed only
  because the default happened to agree about Denmark.

## Running it against an HTTP implementation

```bash
php conformance/run-http.php --base=https://api.example.com --key=sk_…
php conformance/run-http.php --base=http://localhost:8000 --corpus=eu-vat
```

No framework and no dependencies — checking whether we answer what we claim should
not require installing our stack. Plain curl and json.

| Exit | Meaning |
| --- | --- |
| `0` | Every vector passed |
| `1` | At least one did not |
| `2` | **The run could not happen** — unreachable, or no corpus matched |

The third code is the one that matters. A suite that cannot reach the service, or
that matched nothing, must never look like a suite that found nothing wrong.

**This script was written before the API it tests.** A corpus retrofitted to a
service documents whatever that service already does; a corpus written first is a
contract the service has to satisfy. The request shape, the three outcomes and the
field names in `run-http.php` are the specification, executable.

It also holds one thing the library runner cannot: that every answer carries a
`reason` explaining the tax determination so callers can audit it.

## Adding one

Add it to the relevant file under `vectors/`, write the `pins` sentence first, and
run the suite. If the engine disagrees with you, find out which of you is wrong
before changing either — that is the entire value of the exercise.
