---
title: What the register covers
weight: 1
description: The 80 jurisdictions, what is published for each, and where the gaps are.
---

# What the register covers

One register, one schema, for every regime — measured against release
`2026.09.15-202`.

| | |
| --- | --- |
| Jurisdictions | 20 642 |
| Rates | 91 959 |
| Rules | 974, in eleven kinds |
| Categories | 147, of which 116 carry a live rate |
| Regimes | `eu` `us` `europe` `ca` `mx` `sa` `apac` `africa` `cac` `gcc` `me` |

Ask it what it covers rather than trusting this page:

```bash
php artisan tax:data:status
```

## How a rate is found

The key is **(jurisdiction, category, classification)** — not category alone. 93% of
live EU rates carry a CN, CPA or band code, and on that key there is no ambiguity
anywhere in the register. The ambiguity that appears at (jurisdiction, category) is
entirely an artefact of collapsing across classification.

So a supply with a commodity code resolves exactly. Without one, a category with two
live answers refuses the band and takes the **standard rate**, flagged
`heading_ambiguous` — the direction a customer can be refunded from.

Two details that decide real invoices:

- **The longest classification wins, and a shortened match is an inference.** Codes run
  to two, four, six and eight digits, and a chapter can disagree with a subheading
  beneath it: the register has 95 live cases where it does. Austria taxes food at 10%
  under CN 04 and carves CN 0401 10 out at 4.9%.
- **Only what the customer bears.** A digital services tax is a levy on the supplier's
  turnover; summed into a cart it overcharges the customer and under-declares the
  liability in one step.

## Below the state line, in the United States

| How | Where | Precision |
| --- | --- | --- |
| Street range | 15 states, with `--streets` | House number |
| ZIP+4 | 24 Streamlined states | Add-on |
| Polygon | California, New Mexico | The point |
| County name | Florida, Pennsylvania, Hawaii, Virginia | The county |
| State rate | everywhere else | The state share, flagged |

Every authority that applies is summed, or none of them: inside Kansas City a county
and a city both levy (6.5 + 1.0 + 1.625), and a rate short by one authority's share
would be an under-charge stamped authoritative. A local record the store cannot price
abandons the whole stack and returns the state share **flagged** instead.

California files all-in totals rather than components, so a combined record replaces
the state share instead of adding to it — and still decomposes into the state share
and the aggregate local share, because a return is filed against authorities.

**Texas gets no boundary data.** It is not a Streamlined member and publishes no
polygons, so an address there resolves at the state rate, visibly.

## Proved against the register's own deck

The register publishes a conformance deck — addresses in, expected authority set and
rate out — cut from the artifacts a release actually ships and read back through the
same shared resolver this engine uses. `RegisterConformanceTest` runs it.

That is the only cross-check that means anything here. Two readers of one format drift
apart quietly: it is exactly what happened when the resolver package read a
formatVersion 3 artifact as a v2 one and answered "no local authority levies here" for
every address in twenty-four states, with its own suite green throughout.

It is not SST's certification deck, and passing it is not certification.

## Known gaps

- **Street indexes are opt-in** and fifteen states publish one. The other nine
  Streamlined states top out at ZIP+4, which is the state's own choice rather than a
  hole.
- **An undetermined (state, category) pair resolves to taxable.** The retired
  us-tax-data dataset carried an explicit marker where its sources disagreed and the
  engine refused on it; the register's taxability lives in sworn Streamlined answers
  keyed by classification, with no per-category verdict for that marker to be. Taxable
  is the over-charge direction and therefore recoverable — but it is a real difference
  from what this package did before.
- **A jurisdiction the register does not carry still refuses**, because there the
  engine knows nothing rather than knowing a default.
