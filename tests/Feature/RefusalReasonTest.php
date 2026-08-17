<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\RefusalReason;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\Refusal;
use Cbox\Tax\Exceptions\UnresolvedProductTaxability;
use Cbox\Tax\Exceptions\UnresolvedTaxRate;

/**
 * A refusal has to be able to name itself.
 *
 * For one release it could not: `Refusal` was a marker and the message was all a
 * caller had. An HTTP layer wanting to tell a shop what to do about a 422 tried to
 * recover a code by searching the message text for an enum value, and it never once
 * matched — the messages name no enum. A lookup that always falls through is worse
 * than none, because it reads as though the codes are wired up.
 */
function texas(): Jurisdiction
{
    return app(JurisdictionRepository::class)
        ->find(new CountryCode('US'), new SubdivisionCode('US-TX'))
        ?? throw new RuntimeException('US-TX is not resolvable.');
}

it('names itself when no rate is published', function () {
    $refusal = UnresolvedTaxRate::for(texas());

    expect($refusal->reason())->toBe(RefusalReason::RateUnavailable)
        ->and($refusal->reason()->callerCanClose())->toBeFalse();
});

// The two that share a class are NOT the same to a caller, and a class-level answer
// would have to lie about one of them.
it('tells a disagreement apart from a missing fact', function () {
    $undetermined = UnresolvedProductTaxability::for(texas(), TaxClass::Clothing);
    $conditional = UnresolvedProductTaxability::conditional(texas(), TaxClass::Clothing);

    expect($undetermined->reason())->toBe(RefusalReason::TaxabilityUndetermined)
        ->and($conditional->reason())->toBe(RefusalReason::TaxabilityConditional);
});

// This is the field a client actually branches on. Where it is true the request can
// be fixed and retried; where it is false, retrying is pointless and the honest
// response is to fall back and flag the line. Telling a caller to try again on a
// jurisdiction we do not model would be a lie they would act on.
it('separates what the caller can fix from what they cannot', function () {
    $closeable = array_values(array_filter(
        RefusalReason::cases(),
        static fn (RefusalReason $r): bool => $r->callerCanClose(),
    ));

    expect($closeable)->toBe([
        RefusalReason::TaxabilityConditional,
        RefusalReason::ThresholdCurrencyUnknown,
    ]);
});

it('gives every reason a remedy worth reading', function () {
    foreach (RefusalReason::cases() as $reason) {
        expect($reason->remedy())->toBeString()
            ->and(strlen($reason->remedy()))->toBeGreaterThan(60);
    }
});

// A remedy that says "try again" on something the caller cannot change is the exact
// lie this enum exists to prevent, so the wording is held to the flag beside it.
it('does not tell a caller to act on something they cannot close', function () {
    foreach (RefusalReason::cases() as $reason) {
        if ($reason->callerCanClose()) {
            continue;
        }

        expect(strtolower($reason->remedy()))->not->toContain('send the line amount');
    }
});

// Every Refusal in the package must implement reason(). Without this a new one
// compiles, throws, and reaches an HTTP layer as an uncategorised 422 — which is
// where this started.
it('leaves no refusal unable to name itself', function () {
    $silent = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Exceptions/*.php') ?: [] as $file) {
        $class = 'Cbox\\Tax\\Exceptions\\'.basename($file, '.php');

        if (interface_exists($class) || ! is_a($class, Refusal::class, true)) {
            continue;
        }

        if (! method_exists($class, 'reason')) {
            $silent[] = $class;
        }
    }

    expect($silent)->toBe([]);
});
