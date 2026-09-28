<?php

declare(strict_types=1);

use Cbox\Tax\Register\Compile\SectionFetcher;
use Cbox\Tax\Register\Reader\RuleKinds;

/*
 * A rule kind nobody reads is a silent hole: Idaho's minimum taxable sale was
 * published and charged anyway. Every kind the register publishes is either applied
 * or set aside with a reason.
 */

it('names the kinds it neither applies nor has set aside', function (): void {
    expect(RuleKinds::unknown(['threshold', 'something_new', 'rounding', 'something_new', 7]))->toBe(['something_new'])
        ->and(RuleKinds::unknown(RuleKinds::APPLIED))->toBe([])
        ->and(RuleKinds::unknown(array_keys(RuleKinds::REVIEWED)))->toBe([]);
});

it('reads or has set aside every kind of rule the live register publishes', function (): void {
    $fetcher = app(SectionFetcher::class);
    $version = $fetcher->resolve('latest');
    $rules = $fetcher->json("/api/v1/releases/{$version}/sections/rules")['rules'] ?? [];

    $kinds = array_map(static fn (mixed $rule): mixed => is_array($rule) ? ($rule['kind'] ?? null) : null, is_array($rules) ? $rules : []);

    expect(RuleKinds::unknown($kinds))->toBe([], "Release {$version} publishes rule kinds this engine neither applies nor has set aside.");
})->group('e2e');
