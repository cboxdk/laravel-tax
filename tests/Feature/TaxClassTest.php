<?php

declare(strict_types=1);

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxClassGroup;
use Cbox\Tax\Taxability\StaticProductTaxability;

// A merchant maps a product once. Everything after that — which US state exempts
// it, which EU band it falls in — is a mapping the DATA owns.
//
// The earlier category list was built for the US question ("is this taxable in
// this state", a boolean per state, 25 categories) and then reused for the EU,
// which asks a different one ("which band", 87 headings in practice). It reached
// 23% of the EU's published bands. These reach 98%.

it('gives every class a name a merchant can answer', function () {
    // The list came out of tax schedules, and half of it would otherwise be in tax
    // vocabulary. Somebody selling running shoes has to find "Footwear", not
    // "Annex III point 6".
    foreach (TaxClass::cases() as $class) {
        $info = $class->info();

        expect($info->name)->not->toBe('')
            ->and($info->name)->toMatch('/^[A-Z]/')            // a label, not an identifier
            ->and($info->name)->not->toContain('_')
            ->and(strtolower($info->name))->not->toContain('annex');
    }
});

it('gives every class concrete examples, because a name is not always enough', function () {
    // "General goods" and "Digital downloads" are both correct and both vague. The
    // examples are what let a merchant recognise their own product.
    foreach (TaxClass::cases() as $class) {
        expect($class->info()->examples)->not->toBeEmpty($class->value);
    }
});

it('files every class under exactly one group', function () {
    // Fifty-six choices in a flat list is a list nobody reads.
    $counted = 0;

    foreach (TaxClassGroup::cases() as $group) {
        $classes = TaxClass::inGroup($group);

        expect($classes)->not->toBeEmpty($group->value);

        $counted += count($classes);
    }

    expect($counted)->toBe(count(TaxClass::cases()));
});

it('anchors every EU-reducible class to an Annex III point', function () {
    // The anchor is what makes the class checkable by someone who was not there
    // when it was written. Without it the list is one person's opinion about how
    // commerce divides up.
    $reducible = array_filter(TaxClass::cases(), static fn (TaxClass $c): bool => $c->info()->mayBeReducedInEu());

    expect(count($reducible))->toBeGreaterThan(35);

    foreach ($reducible as $class) {
        // Annex III runs to 29 points. A number outside it is a typo, not a rule.
        expect($class->info()->annexIII)->toBeGreaterThan(0)->toBeLessThanOrEqual(29);
    }
});

it('says plainly which classes EU law does NOT permit a reduced rate for', function () {
    // Null is a determination, not a gap. Consumer electronics, off-the-shelf
    // software and professional services are standard-rated everywhere in the EU
    // because no Annex III point covers them — so a class with no point should
    // never resolve to a band, and that is checkable here rather than discovered
    // when a band appears from somewhere.
    foreach ([TaxClass::Electronics, TaxClass::Furniture, TaxClass::SoftwarePrewritten, TaxClass::ProfessionalService, TaxClass::DigitalService] as $class) {
        expect($class->info()->mayBeReducedInEu())->toBeFalse($class->value);
    }
});

it('carries CN headings for goods and none for services', function () {
    // TEDB scopes its own rates by CN, so this is the source's language rather
    // than ours. Services are not described by CN at all, and claiming a heading
    // for one would be an anchor pointing at nothing.
    expect(TaxClass::Footwear->info()->cnPrefixes)->toBe(['64'])
        ->and(TaxClass::Book->info()->cnPrefixes)->toBe(['4901'])
        ->and(TaxClass::ProfessionalService->info()->isGoods())->toBeFalse()
        ->and(TaxClass::Accommodation->info()->isGoods())->toBeFalse();
});

it('separates the pairs a single jurisdiction lumps together', function () {
    // Ireland reports books AND periodicals under one heading, at 0% and 9% at
    // once, and no single answer fits both. Two classes make it decidable. The
    // same holds for prescription and over-the-counter medicine, which the EU
    // rates as one heading and the US splits.
    expect(TaxClass::Book)->not->toBe(TaxClass::Periodical)
        ->and(TaxClass::PrescriptionMedicine)->not->toBe(TaxClass::OtcMedicine)
        ->and(TaxClass::Clothing)->not->toBe(TaxClass::Footwear);
});

it('has exactly one class that is safe as a default', function () {
    // General tangible goods are standard-rated in every sales-tax state and every
    // Member State, so a merchant who picks nothing is over-charged rather than
    // under-charged. Every other class must be chosen deliberately.
    expect(TaxClass::default())->toBe(TaxClass::GeneralGoods)
        ->and(TaxClass::GeneralGoods->info()->mayBeReducedInEu())->toBeFalse();
});

it('does not carry a class for the things that are not products', function () {
    // Five EU "headings" are territorial or rate-mechanism artefacts sharing a
    // field with real categories — REGION is the Azores and Corsica, not a thing
    // anyone sells. Mapping them blindly would have produced a product class for
    // a place.
    $names = array_map(static fn (TaxClass $c): string => $c->value, TaxClass::cases());

    foreach (['region', 'parking_rate', 'temporary_exemption', 'household'] as $artefact) {
        expect($names)->not->toContain($artefact);
    }
});

// ---- Migration --------------------------------------------------------------

it('translates every superseded category to exactly one class', function () {
    // Nothing may be dropped: a stored value on a merchant's product has to
    // convert, or the migration silently reclassifies their catalogue.
    foreach (TaxCategory::cases() as $category) {
        expect($category->toClass())->toBeInstanceOf(TaxClass::class);
    }

    expect(TaxCategory::Standard->toClass())->toBe(TaxClass::GeneralGoods)
        ->and(TaxCategory::Grocery->toClass())->toBe(TaxClass::Groceries)
        // Clothing does NOT split into footwear on the way across: the old list did
        // not distinguish them, and inventing the distinction during a migration
        // would reclassify a merchant's shoes without being asked.
        ->and(TaxCategory::Clothing->toClass())->toBe(TaxClass::Clothing);
});

it('keeps an override written against the old names working', function () {
    // Seventeen of the twenty-five values changed name. An override is the kind of
    // thing an operator wrote once, put in a config file and forgot — and left to
    // break, the failure is the worst shape available: the key stops matching, the
    // override silently stops applying, and a category somebody deliberately
    // configured falls back to the default.
    $legacy = new StaticProductTaxability([
        'US-CA:grocery' => false,          // the superseded name
        'US-TX:groceries' => false,        // the current one
    ]);

    $geo = $this->app->make(JurisdictionRepository::class);
    $ca = $geo->find(new CountryCode('US'), new SubdivisionCode('US-CA'));
    $tx = $geo->find(new CountryCode('US'), new SubdivisionCode('US-TX'));

    expect($legacy->determine($ca, TaxClass::Groceries, anyAmount())->isExemptFor(anyAmount()))->toBeTrue()
        ->and($legacy->determine($tx, TaxClass::Groceries, anyAmount())->isExemptFor(anyAmount()))->toBeTrue();
});
