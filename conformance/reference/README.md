# Independent calculation references

On 18 September 2026, all **41 cases** in [the corpus](2026-09-17.json) passed
against register release `2026.09.16-236`, after fixing inclusive delivery totals.
The register supplies rate and rule data, never the expected answers. Full `composer qa`
passed after the rule and compatibility fixes with **574 tests and 2,031 assertions**, including lint, static analysis,
licence checks and the dependency audit.

These are combined data-and-engine checks. The
[validation matrix](../validation-matrix.md) separates published facts, executable
logic, adapters and host inputs, and identifies what each test can establish.

An additional live regression in `RegisterReferenceTest` now checks dated PA/AZ
rules, CT/NY AND operators, an IL invoice-rounding example and a KS delivery with
confirmed exclusion conditions against the same pinned release. These checks are
separate from the 41 JSON cases. The IL example verifies arithmetic at a state
rate, not an address-specific all-in quote. KS's zero delivery tax depends on the
host's confirmation of all exclusion conditions. Outstanding publication requests
are in [cadastre feedback](../cadastre-feedback.md).

```bash
composer test:reference
CBOX_TAX_REFERENCE_RELEASE=latest composer test:reference
```

The first command pins the recorded release. The second checks a newer release
against the same supply dates. Both use a fresh temporary store, the real sync and
verification commands, public calculator bindings and offline pricing. They run
in the normal `composer qa` gate as the `e2e` / `reference` group.

## US local-rate references

These cases use synthetic USD 100 purchases of ordinary taxable goods. Rates were
checked on 18 September 2026 against the
[Washington Department of Revenue's third-quarter table](https://dor.wa.gov/sites/default/files/2026-05/Q326_LSU-flyer-by-county.pdf)
and the Florida Department of Revenue's
[2026 county surtax table](https://floridarevenue.com/Forms_library/current/dr15dss_26.pdf)
and [general state rate](https://floridarevenue.com/taxes/taxesfees/Pages/sales_tax.aspx).
Expected tax and gross amounts are arithmetic derived from those rates:

| Supplied locality | Combined rate | Tax | Gross |
| --- | ---: | ---: | ---: |
| Miami-Dade County, FL | 7% | $7.00 | $107.00 |
| Seattle, WA | 10.55% | $10.55 | $110.55 |
| Hillsborough County, FL | 7.5% | $7.50 | $107.50 |

The test also checks rate components, allocated tax reconciliation, provenance and
absence of a partial-rate warning. Seattle's official 4.05% local share is a
combined amount represented at city level in the register; this does not validate
the split among individual local authorities.

The seller has an explicit registration in each destination state. Cbox receives
county `Miami-Dade County`, ZIP `98109`, or county `Hillsborough County` respectively.
The source tables establish locality rates, not street-to-jurisdiction assignments.
These cases do not test an external address geocoder.

## Official references

The other 38 cases cover:

- All 27 EU national standard VAT rates from the
  [European Commission table](https://europa.eu/youreurope/business/finance-and-tax/vat/vat-rules-rates/index_en.htm).
- The UK standard rate and an inclusive-price example from
  [HMRC](https://www.gov.uk/hmrc-internal-manuals/business-income-manual/bim31505),
  plus [zero-rated ordinary printed books](https://www.gov.uk/guidance/zero-rating-books-and-printed-matter-for-vat-notice-70110).
- [French printed books at 5.5%](https://bofip.impots.gouv.fr/bofip/1437-PGP.html/identifiant%3DBOI-TVA-LIQ-30-10-40-20260729).
- Finnish groceries today and on either side of 1 January 2026, when the reduced
  rate changed from 14% to 13.5%, and the mixed delivery example, from
  [the Finnish Tax Administration](https://www.vero.fi/en/businesses-and-corporations/taxes-and-charges/vat/rates-of-vat/).
- [Cross-border EU B2B services](https://europa.eu/youreurope/business/finance-and-tax/vat/cross-border-vat/index_en.htm),
  with the customer's VAT ID asserted as validated.
- Danish inclusive-price and credit-note arithmetic using the sourced standard rate.

Where a source supplies only a rate or rule, the expected monetary amounts are
arithmetic derived from it. The JSON distinguishes rate-based expectations,
published examples, rules and arithmetic regressions. Do not regenerate expected
values from engine output.

## Defect found and fixed

The Finnish example has EUR 60 groceries, EUR 20 cleaning products and EUR 12
delivery, all inclusive. The delivery is split EUR 9 / EUR 3 by gross selling
prices. With `ApportionmentBasis::GrossValue`, Cbox now returns EUR 1.68 delivery
VAT and a EUR 92.00 order total (EUR 12.88 VAT).

Before the fix, delivery always reported its input as net and added the assessed
VAT on top. With the previous default net-value allocation this example produced
EUR 93.66 gross from EUR 92.00 of inclusive prices. The fix sums the assessed net,
tax and gross portions. Five offline regressions cover inclusive pricing, line
overrides, refunds and gross-value allocation. `NetValue` remains the default;
the caller selects the allocation basis appropriate to the transaction.

## Scope still unverified

The three US cases cover general local rates with supplied localities and explicit
seller registrations. Product classification, exemption thresholds, origin
sourcing and tax holidays still need independent transaction references from tax
authorities. Existing fixtures check engine behaviour against controlled inputs.

Each new reference must record seller registrations, supply locations and date,
currency, product classification, inclusive/exclusive pricing, delivery and
exemptions. Compare treatment, line taxes, totals and jurisdiction shares only
where the source independently establishes them.
