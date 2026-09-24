---
title: What the register covers
weight: 1
description: What the register publishes, how a rate is found, and where the gaps are.
---

# What the register covers

One register, one schema, for every regime — measured against release
`2026.09.22-261`. The figures move with every release, which is why the command below
is the answer and this table is only an illustration.

| | |
| --- | --- |
| Jurisdictions carrying a rate | 20 635 |
| Rate records | 92 807 |
| Rules | 793, in twelve kinds |
| Categories | 182 |
| Countries and states covered | 293 |
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
| Polygon | California, New Mexico, Texas | The point |
| County name | Florida, Pennsylvania, Hawaii, Virginia | The county |
| State rate | everywhere else | The state share, flagged |

Every authority that applies is summed, or none of them: inside Kansas City a county
and a city both levy (6.5 + 1.0 + 1.625), and a rate short by one authority's share
would be an under-charge stamped authoritative. A local record the store cannot price
abandons the whole stack and returns the state share **flagged** instead.

**A ZIP is a mail route, not a tax boundary.** Washington's 98001 holds Federal Way
and Auburn at 10.4% and unincorporated King County at 10.3%. Asked with the bare five
digits, the store can only return one of the ZIP's answers, so a ZIP split between
authority sets comes back flagged `RateLimit::PostcodeSpansLocalities` with the ZIP+4
or the street as the remedy; the ZIP+4 settles it. A ZIP whose addresses all share one
set is decided by its five digits and is not flagged. A host can ask before it
geocodes: `RegisterBoundaries::zipIsUniform('WA', '98001')` is `false`, `true` for a
uniform ZIP, and `null` where the store holds no postal data for it.

**Outside every polygon is not automatically "no local tax".** The register says per
state whether its artifacts leave any levying ground out, as a dated list of the codes
they never place (`absence.blockedBy`). Where none of those is in force on the supply
date — California and New Mexico — a point in no polygon is priced at the state rate,
authoritative. Where one is — Texas has a district in force with no polygon, and
districts starting on 1 October before its layer carries them — the point stays
unresolved and flagged. A ZIP missing from a postal file is never read as "no local
tax": it is a gap in the file. The register also says which level a local answer needs
(`resolution`); a state that needs only the county is resolved from the county's name.
A release that predates these fields behaves as before.

California files all-in totals rather than components, so a combined record replaces
the state share instead of adding to it — and still decomposes into the state share
and the aggregate local share, because a return is filed against authorities.

**Texas resolves by point.** The Comptroller publishes the Rate Locator's layers —
cities, combined areas, transit, special purpose districts and counties — and the
register ships them as a polygon file. A combined area stands **in place of** the
city, district or county it combines (geometry format 3, `replaces`), so those are
dropped wherever it covers the point. A polygon layer lists the locals, not the state:
the state share is added to every point answer, which is what a state filing local
**components** needs, and changes nothing where locals are **combined** totals
(California, New Mexico).

## Proved against the register's own deck

The register publishes a conformance deck — addresses in, expected authority set and
rate out — cut from the artifacts a release actually ships and read back through the
same shared resolver this engine uses. `RegisterConformanceTest` checks the expected authority sets for Kansas and Arkansas.
It does not compare the deck's rate totals; calculation and live-sync tests are
separate.

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
