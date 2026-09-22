---
title: The register
weight: 2
description: Where the rate data comes from, how to sync it, and what the licence permits.
---

# The register

The default sources read their rates, US rules and address boundaries from
**[data.cboxtax.com](https://data.cboxtax.com)**, a published register of consumption-tax
jurisdictions covering 80 jurisdictions across eleven regimes — the EU, the US, the UK
and the rest of Europe, Canada, Mexico, Latin America, Asia-Pacific, Africa, the
Caribbean, the Gulf and the wider Middle East. The engine currently models 52
countries; see [Supported jurisdictions](../coverage/supported.md) for the
calculation coverage.

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
| `tax:data:sync` | Compile a release, activate it and prune according to retention |
| `tax:data:status --offline` | Active release, configured pin, pricing version and installed scope |
| `tax:data:activate <version>` | Change the active release; an explicit pricing pin still wins |
| `tax:data:verify [release]` | Check file sizes and hashes against the local compiled manifest |
| `tax:data:prune --keep=2` | Delete old versions |

`activate` is the rollback, and it costs one `rename` — no download, no network. That
matters because a bad release is discovered exactly when you would rather not be
downloading another. `activate previous` takes the one before the live one.

`sync --check` compares the pricing version with the configured release, or the
latest published release when no pin is configured. It exits non-zero when they
differ or the required register is missing. `--release=latest` explicitly checks
against the publisher even when pricing is pinned.

`verify` defaults to the version used for pricing and performs no network request.
A missing file, changed content or invalid manifest makes it fail. It checks local
integrity against the manifest created during sync; it does not authenticate the
publisher or establish that a rate is legally correct.

## Configuration and version pins

Publish `config/tax.php` with `php artisan vendor:publish --tag=tax-config`.

| Config under `tax.register` | Environment | Default |
| --- | --- | --- |
| `url` | `TAX_REGISTER_URL` | `https://data.cboxtax.com` |
| `store` | `TAX_REGISTER_STORE` | `storage/app/cbox-tax/register` |
| `version` | `TAX_REGISTER_VERSION` | Active release; sync selects latest |
| `regions` | `TAX_REGISTER_REGIONS` | All published regions |
| `states` | `TAX_REGISTER_STATES` | All US states |
| `streets` | `TAX_REGISTER_STREETS` | No street indexes |
| `boundaries` | `TAX_REGISTER_BOUNDARIES` | `true` |
| `keep` | `TAX_REGISTER_KEEP` | `2` |

Lists accept PHP arrays or comma-separated environment values. Explicit CLI values
replace the corresponding sync defaults. `--no-boundaries` disables the boundary
download for that run.

Set `TAX_REGISTER_VERSION=2026.09.16-236` to pin both pricing and the default sync
target. A missing pinned release refuses, even if another release is active.
Running `sync --release=latest` or `activate` changes the active pointer, but does
not override a configured pin. `status` shows both.

Sync prunes automatically using `keep`; `sync --keep=3` overrides it for one run.
Pruning always preserves the active release and the configured pin, so it can
retain more than the requested count. `activate previous` selects the most recent
installed release older than the active one.

The dataset holds its selected version for the lifetime of the application
instance. Restart long-running queue workers and Octane processes after changing
the release or pin, and retain releases that are still used by running workers.

Schema compatibility is checked during sync and again before reading an installed
store. This reader accepts reviewed schema versions through `1.34.x`; a newer
minor or major requires a package update. Unknown fields on consumed rules also
refuse, so a new condition cannot be dropped while retaining the old boolean or
rate decision. Do not strip conditions or edit the schema version to bypass this
check. The [draft consumer contract](../../conformance/cadastre-consumer-contract.md)
describes the proposed extension and the publication barrier needed by older readers.

## Take less than everything

```php
'register' => [
    'regions' => ['eu', 'us'],    // only the data regions you need
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
`status --offline` reads only local files. Without that option, status also attempts
to report the latest published release; an unreachable publisher does not hide the
local installation.

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
