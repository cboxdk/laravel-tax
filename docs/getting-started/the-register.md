---
title: The register
weight: 2
description: Where the rate data comes from, how to sync it, and what the licence permits.
---

# The register

Every rate, rule and boundary this engine applies comes from one place:
**[data.cboxtax.com](https://data.cboxtax.com)**, a published register of consumption-tax
jurisdictions covering 80 jurisdictions across eleven regimes — the EU, the US, the UK
and the rest of Europe, Canada, Mexico, Latin America, Asia-Pacific, Africa, the
Caribbean, the Gulf and the wider Middle East.

It is **not fetched while pricing**. `tax:data:sync` compiles a published release into a
local store; the engine reads that store and makes no network call at all.

## Install it before you price anything

```bash
php artisan tax:data:sync
```

Put it in your deploy alongside `php artisan migrate`. Until it has run, the engine
**refuses** rather than guessing, and the refusal names this command.

That refusal is deliberate. No rate data ships inside this package — it is MIT and the
register is PolyForm Internal Use, and bundling one inside the other would mislabel it
— so a fresh install genuinely has nothing. The only unacceptable behaviour would be a
plausible number: an invoice priced from a stale snapshot or a guessed standard rate is
wrong in a way nobody notices until a return is filed.

The first sync pulls about 6.5 MB over the wire and writes about 63 MB.

## Why a local store rather than an API call

Two reasons, and both are load-bearing.

**The register publishes several times a day.** Four releases landed on 2026-09-15
alone. A rate fetched per request can move under a half-priced order, so which release
is live has to be a decision somebody makes rather than a cache expiring.

**The US region cannot be decoded in a PHP process.** It is 48.8 MB of JSON, which
`json_decode` turns into 315 MB of PHP arrays — it does not blow a generous memory
limit, it blows the default one. So the store is not a copy of the API: records are
sharded by what a lookup actually names and indexed by jurisdiction, and a lookup reads
one index and one record. Pricing a Danish invoice touches 12 KB.

## The commands

| | |
| --- | --- |
| `tax:data:sync` | Compile a release and make it live |
| `tax:data:status` | What is installed, what is live, what it covers |
| `tax:data:activate <version>` | Make an already-installed version live |
| `tax:data:verify` | Re-hash every file against the manifest |
| `tax:data:prune --keep=2` | Delete old versions |

`activate` is the rollback, and it costs one `rename` — no download, no network. That
matters because a bad release is discovered exactly when you would rather not be
downloading another. `activate previous` takes the one before the live one.

`sync --check` compares the installed version against the published one for a few
kilobytes and exits non-zero when behind, so a deploy or a cron can gate on it without
pulling anything.

## Take less than everything

```php
'register' => [
    'regions' => ['eu'],          // only the regimes you sell into
    'states' => ['TX', 'CA'],     // only the US states you sell into
    'boundaries' => true,
],
```

Narrowing is safe **because a jurisdiction outside what you compiled refuses** instead
of answering from an absence. A store built `--region=eu` does not quietly report "no
tax in Japan".

The weight is almost entirely American: all ten non-US regimes together come to 4 MB,
the US is 38 MB of rates, and Washington alone is 13.4 MB of that. A shop selling only
into Texas wants `--state=TX`.

**Street indexes are asked for by name.** They are 228 MB across fifteen states against
20 MB for every ZIP index in the register, and they buy one rung of the resolution
ladder — a house number instead of a ZIP+4. `--streets=KS,WA` fetches them for those
states; without one, an address there resolves at ZIP+4, which is a *visible*
confidence grade rather than a silent loss.

## Rolling back

```bash
php artisan tax:data:status
php artisan tax:data:activate 2026.09.15-199
```

A version stays on disk until pruned, so the previous one is normally right there.
`status` answers offline on purpose — it still tells you what you are billing from when
the network is the thing that is broken.

## Licence

This package is MIT. **The register it compiles is not.**

The register is published under the
[PolyForm Internal Use Licence 1.0.0](https://polyformproject.org/licenses/internal-use/1.0.0/).

**You may** use it inside your own organisation for anything, including commercially.
Pricing your own sales is exactly the intended use. You may keep copies, read every
byte, and check any figure against your own reading of the same statute.

**You may not** pass the data on: redistribute it, bundle it into something you ship,
or offer your customers a rate lookup, an API or a product feature that gives them the
rates themselves.

The line is *charging your customers tax you computed with it* (fine) against *telling
your customers what the rates are* (not covered). The second needs a licence from Cbox
— something you buy when you cross the line, not a gate on getting started. There is no
key, no signup, and nothing about the register you cannot evaluate before deciding to
depend on it.
