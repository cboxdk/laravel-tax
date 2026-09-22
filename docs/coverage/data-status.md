---
title: Beta status and responsibility
weight: 0
description: The register's data is in beta and can be wrong in ways nothing here detects. What the engine guarantees, what it does not, and what stays yours.
---

# Beta status and responsibility

**The register's data is in beta.** It is read from primary sources — statutes,
tax-authority publications, official rate tables — and every rate carries the source
and the date it was captured. That is a much better starting point than a scraped
list, and it is still not a guarantee. **There will be errors nobody has found yet.**

The engine is the same: it models a large amount of law, it is tested against an
independent reference corpus, and it is young. Both halves get corrected as errors
surface.

## What this package does guarantee

Narrow things, and it holds them firmly:

- **It does not invent numbers.** Every rate comes from a published record with its
  provenance attached. Where nothing published answers, the engine raises an
  exception rather than returning a plausible figure.
- **It says when it is unsure.** `confidence` and `limitedBy` mark an answer that is
  the best available rather than exact, and each limit carries the one step that
  would close it.
- **It is reproducible.** Pin a release and the same query gives the same answer,
  offline, for as long as you keep that release installed.

## What it does not

- **It cannot know what it does not know.** `Confidence::Authoritative` means "the
  published data answered exactly what was asked" — not "this figure is correct in
  law". A rate transcribed wrongly, a rule that changed without the authority
  publishing it clearly, a category read more narrowly than a tax office would read
  it: none of these leave a mark on the assessment.
- **It is not tax advice.** Which regime a business falls under, whether a supply is
  what you think it is, whether a registration is required, what gets filed and when
  — those are questions for a qualified adviser in that jurisdiction.
- **It does not file anything.** The engine calculates and explains. Registration,
  returns, remittance and e-invoicing mandates stay outside it.
- **It carries no warranty.** The package is MIT and the register's data is licensed
  separately; both are provided as-is, without warranty of any kind. Neither licence
  makes anyone liable for a tax outcome.

## What stays yours

You remain responsible for the tax you charge, collect and file. Practically, that
means three habits worth building in from the start:

1. **Act on the flags.** `OrderAssessment::needsReview()` exists so that an uncertain
   answer can be caught before an invoice goes out rather than at an audit. Decide
   what your application does with it — block, queue for review, or record it — and
   make that decision deliberately.
2. **Sanity-check the places that matter to you.** The countries and categories you
   sell most in deserve a one-off check against your own adviser's numbers, and a
   re-check when you enter a new market. A hundred jurisdictions you never sell into
   being right is worth less than the three you live in.
3. **Store what you charged, and why.** Keep the treatment, the reason, the release
   version and the provenance on the order — see
   [the checkout recipe](../cookbook/webshop-checkout.md). When a rate turns out to
   have been wrong, that record is the difference between correcting the affected
   invoices and guessing at them.

## Reporting an error

A wrong number is worth more to us than a working one, because it is the only way
the data improves. Open an issue on
[cboxdk/laravel-tax](https://github.com/cboxdk/laravel-tax/issues) with the query,
the answer you got and the answer you expected, plus the release version from
`$assessment->rate?->provenance?->version`. That last field is what makes a report
reproducible instead of anecdotal.

Errors in the *data* are corrected in the register and reach you through
`tax:data:sync`; errors in the *logic* are fixed in the package. The reply will say
which one it was.
