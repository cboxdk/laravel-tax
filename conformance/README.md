# Conformance vectors

Cases this engine holds itself to, as plain JSON with no PHP in it. Each vector is a
determination — a supply in, an answer out — and a sentence saying what it pins.

```bash
vendor/bin/pest tests/Feature/ConformanceTest.php
```

## Why this exists

**One engine, several consumers.** The library, the HTTP API and any embedded
integration are three ways into the same determination, and three things that drift
apart quietly. Each keeps passing its own tests while disagreeing with the others,
and nobody finds out until a customer does. A shared corpus is the only thing that
makes disagreement visible.

**And it is meant to be handed over.** In a category where no vendor discloses how
an answer is reached, the cheapest way to be argued with is to publish the cases you
hold yourself to. Take these, run them against this engine, run them against
something else, and see who answers what.

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

They run against the committed dataset slice in `tests/Fixtures/`, never the live
published mirror. A rate that changes in the world must not silently change what a
vector asserts — that would make this a mirror of today's data instead of a
description of behaviour. When a rate genuinely changes, the fixture is updated
deliberately and the diff is reviewable.

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

- **Hungary was assumed to reduce groceries.** It does not; food is charged at the
  full 27%. Assuming every member state reduces food is how an intuition becomes an
  under-charge. The vector now pins the standard rate, and a second one pins France
  at 5.5% so both directions are covered.
- **An empty registration list was read as "charge nothing."** That is US thinking:
  there, no nexus means you must not collect. In the EU a distance seller is obliged
  to collect through OSS, and relief must be *affirmatively asserted* — silence is
  not relief, and not being registered is non-compliance rather than an exemption.

Neither was an engine defect. Both would have shipped as confident wrong answers
without a corpus to state them out loud.

Then the order shape found three more, and these were real:

- **Delivery had no representation whatsoever.** Article 78(b) makes a delivery charge
  part of the taxable amount of what it delivers, so postage on a cart of books is
  charged at the books' rate. There was no way to say a line was delivery, so a caller
  had to pick a class for it and got 20% where 5.5% was due — on the single most
  common line in e-commerce.
- **The tax was rounded once per line.** Three lines at 5.5% sharing a 10.00 charge
  gave 3.33, 3.33 and 3.34, each taxed to 0.18, totalling 0.54 against the 0.55 that
  10.00 at 5.5% actually is. Apportioning per RATE rather than per line rounds once.
- **The order runner was not pinned to the fixture.** It resolved the calculator from
  the container, which reached for the default rate source, so an order vector
  asserted nothing about the data this corpus claims to pin. The first one passed only
  because the default happened to agree about Denmark.

## Adding one

Add it to the relevant file under `vectors/`, write the `pins` sentence first, and
run the suite. If the engine disagrees with you, find out which of you is wrong
before changing either — that is the entire value of the exercise.
