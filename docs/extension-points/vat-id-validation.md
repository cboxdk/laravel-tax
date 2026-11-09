---
title: Tax-ID validation
weight: 3
description: Validate a customer's VAT/registration number (VIES, HMRC, ABN) — the reverse-charge hinge, fail-safe by design.
---

# Tax-ID validation

Reverse-charge zero-rating legally hinges on a **validated** business tax ID, so
the engine validates it before treating a cross-border B2B supply as reverse-charge.
The `VatIdValidator` contract does this, routing to the authoritative registry per
country:

- **EU** → VIES (`ViesValidator`) — returns a consultation reference (proof).
- **UK** → HMRC "Check a UK VAT number" (`HmrcVatValidator`).
- **Australia** → ABN Lookup (`AbnLookupValidator`, needs a GUID).

```php
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Geo\ValueObjects\CountryCode;

$result = app(VatIdValidator::class)->validate(new CountryCode('DE'), 'DE123456789');

$result->permitsReverseCharge();   // true only when conclusively valid
$result->consultationReference;    // proof-of-check id, record for audit
```

Feed `permitsReverseCharge()` into the `customerTaxIdValidated` flag on a `TaxQuery`.

## Fail-safe by design

`VatIdValidation` carries a `conclusive` flag. When the service is unreachable or
cannot determine validity, the result is **inconclusive** — `permitsReverseCharge()`
is `false`, so the supply is taxed rather than wrongly zero-rated. A transport error
never throws and never reads as "valid".

## Timeouts and registry outages

A lookup usually runs while a customer waits, so every registry call is bounded:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `tax.vat_id.connect_timeout` | `TAX_VAT_ID_CONNECT_TIMEOUT` | `3` | Seconds to open the connection. |
| `tax.vat_id.timeout` | `TAX_VAT_ID_TIMEOUT` | `6` | Seconds the whole request may take. |
| `tax.vat_id.unavailable_backoff` | `TAX_VAT_ID_UNAVAILABLE_BACKOFF` | `60` | Seconds to stop asking a registry that is down, per country. `0` turns it off. |
| `tax.vat_id.backoff_store` | `TAX_VAT_ID_BACKOFF_STORE` | default store | Cache store that holds the backoff. |

A missing, empty or non-positive timeout falls back to its default, never to the
HTTP client's 30 seconds. A config file published by an older release works as is.

A registry is **unavailable** when the call times out, the connection fails, or it
answers 5xx or 429. The lookup then returns inconclusive, as before, and the
validator remembers the outage for that registry and country for
`unavailable_backoff` seconds. Lookups in that window return inconclusive at once,
without a network call, so an outage costs one timeout per country per window rather
than one per request. The key is per country because VIES fails one member state at
a time: Germany being down does not stop French lookups.

What the backoff does not do:

- It never changes a valid or invalid answer. A registry that answers — including
  HMRC's 404 "not registered" — never trips it.
- It never makes an ID read as valid. It only ever produces "could not verify",
  which charges tax.
- It does not cache answers. Caching valid and invalid results is the caller's
  choice; cache only conclusive results so an outage is retried after the window.

Use a cache store shared by all your servers (Redis, database) so they back off
together. If the cache itself fails, the lookup goes to the registry as if there
were no backoff. Hosts that build the validators by hand pass a
`Cbox\Tax\Validators\RegistryBackoff` as the `backoff:` argument; without one
there is no backoff, but the timeouts still apply (3 and 6 seconds by default).

## Configuration

VIES and HMRC are bound out of the box. To enable Australian ABN validation, set an
ABN Lookup GUID:

```php
// config/tax.php  (or .env: ABN_LOOKUP_GUID=...)
'vat_id' => ['abn_guid' => env('ABN_LOOKUP_GUID')],
```

Bind your own `VatIdValidator` (or add validators to the dispatcher) for other
countries. Use `Cbox\Tax\Testing\FakeVatIdValidator` in tests — no network.

> The adapters implement each service's documented request/response shape; verify
> against the live API (and provision production credentials) before relying on them.
