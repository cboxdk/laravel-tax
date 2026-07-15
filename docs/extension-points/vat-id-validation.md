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
