---
title: Exemptions
weight: 3
description: Express a buyer tax exemption natively and get a native Exempt assessment, applied deny-by-default over the regime's verdict.
---

# Exemptions

A buyer may hold a certificate that removes the tax it would otherwise be charged —
a US **resale** permit, a **nonprofit** or **government** exemption, or another
jurisdiction-specific basis. The engine accepts this as a native input on the query
and, when it is valid and covers the taxed jurisdiction, returns a native `Exempt`
assessment.

## The boundary: the engine computes; the consumer captures

The engine deliberately does **not** capture or verify the underlying certificate.
Storing the certificate, checking its expiry, and verifying it against a tax
authority are the **consumer's** concern (a certificate store in your app). The
consumer expresses the *result* of that verification as a `TaxExemption` value
object, and the engine applies it. This keeps `TaxQuery` free of buyer identity: it
carries the *place* of supply and an *asserted, verified* exemption, not a customer
record.

## The input

```php
use Cbox\Tax\ValueObjects\TaxExemption;
use Cbox\Tax\Enums\ExemptionType;
use Cbox\Geo\ValueObjects\{CountryCode, SubdivisionCode};

$exemption = new TaxExemption(
    type: ExemptionType::Resale,              // Resale | Nonprofit | Government | Other
    reference: 'CA-RESALE-42',                // opaque certificate id, kept on the assessment
    countries: [],                            // country-level coverage (EU/national VAT)
    subdivisions: [new SubdivisionCode('US-CA')], // sub-federal coverage (US states, CA provinces)
    validFrom: null,                          // optional validity window (DateTimeImmutable)
    validUntil: null,
);

$assessment = app(TaxCalculator::class)->assess(new TaxQuery(
    // …amount, pricing, place, customer, seller…
    exemption: $exemption,
));
```

`TaxQuery::$exemption` is optional and defaults to `null` — every existing query is
unaffected.

## Precedence — deny-by-default, overrides only Standard

The exemption is applied **last**, as an override of the regime's verdict. It only
rewrites a would-be **`Standard`**-taxed line to `Exempt`; every other treatment is
left exactly as the regime decided:

| Regime verdict | With a valid, covering exemption |
| --- | --- |
| `Standard` (tax would be charged) | → **`Exempt`** (net kept, tax 0, gross = net) |
| `ReverseCharge` (cross-border B2B, buyer self-accounts) | unchanged |
| `NotRegistered` (no seller nexus) | unchanged |
| `ZeroRated` (a real 0% rate) | unchanged |
| `Exempt` (already out of scope, e.g. a non-taxable product) | unchanged |

This is the same precedence an app-layer decorator over the calculator would
implement — an exemption never manufactures tax where the regime charged none, and
never competes with reverse-charge or nexus rules. It only relieves a supply the
seller would otherwise have to tax.

An exemption is **ignored** (the standard tax stands) when:

- it does not cover the place of supply (see matching, below);
- it is expired (`validUntil` in the past) or not yet valid (`validFrom` in the
  future);
- the supply was not going to be standard-taxed in the first place.

## Jurisdiction matching

Coverage is matched at the granularity of the **taxing jurisdiction** — the
`placeOfSupply` on the assessment, which for EU micro-business origin sourcing is
the seller's country, not the buyer's:

- **Sub-federal place** (a US state, a Canadian province): only a matching
  `subdivisions` entry exempts. A bare `countries` entry does **not** — exemption
  certificates there are issued per state/province, so a country-wide claim is
  refused.
- **National place** (an EU Member State, the UK, …): a matching `countries` entry
  exempts.

## On the assessment

A certificate-driven exemption records the driving `TaxExemption` on the result, so
the reference and basis survive into your audit trail:

```php
$assessment->treatment;            // TaxTreatment::Exempt
$assessment->tax->isZero();        // true — net kept, gross = net
$assessment->exemption?->reference;// 'CA-RESALE-42'
$assessment->exemption?->type;     // ExemptionType::Resale
$assessment->reason;               // 'Exempt: resale exemption (ref: CA-RESALE-42) covers US-CA; standard tax overridden.'
$assessment->isExempt();           // true
```

`TaxAssessment::$exemption` is `null` for every non-certificate outcome, including
an `Exempt` treatment that is *out of scope* rather than certificate-driven (e.g. a
product that is simply not taxable in the state) — so a reader can tell the two
apart.

See [testing](../getting-started/testing.md) for the dogfooded
`InteractsWithTax::taxExemption()` builder and `assertExempt()` helper.
