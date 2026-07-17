<?php

declare(strict_types=1);

namespace Cbox\Tax\Taxability;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxCategory;

/**
 * A taxability matrix backed by a static override map. Everything is taxable by
 * default; overrides mark specific jurisdiction/category combinations as exempt.
 * This is a safe, predictable default — production should bind a matrix sourced
 * from an authoritative feed (e.g. the SST taxability matrices) for per-state SaaS
 * taxability, which is DATA that changes.
 */
readonly class StaticProductTaxability implements ProductTaxability
{
    /**
     * @param  array<string, bool>  $overrides  Key "<jurisdiction>:<category>" => taxable,
     *                                          e.g. "US-CA:digital_service" => false.
     */
    public function __construct(private array $overrides = []) {}

    public function isTaxable(Jurisdiction $jurisdiction, TaxCategory $category): bool
    {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        return $this->overrides[$where.':'.$category->value] ?? true;
    }

    /**
     * Curated per-state SaaS (digital-service) taxability for the United States,
     * keyed `"US-XX:digital_service" => taxable`. Sourced from two authoritative,
     * dated practitioner compilations (TaxJar and Anrok SaaS-by-state guides,
     * retrieved 2026-07-17); only states where BOTH compilations agree on a clear
     * taxable/exempt determination are included. See
     * `docs/coverage/us-saas-taxability.md` for the per-state citations and the
     * states left UNDETERMINED (conflicting sources, or B2B/B2C-conditional and
     * partial regimes a boolean cannot represent) — those are deliberately ABSENT
     * so an operator must configure them.
     *
     * The map covers the `digital_service` category only; tangible goods
     * (`standard`) remain taxable-by-default. State-level determinations do not
     * account for home-rule localities (e.g. Chicago, Colorado home-rule cities),
     * which may tax SaaS even where the state does not.
     *
     * @return array<string, bool>
     */
    public static function unitedStatesSaas(): array
    {
        $taxable = [
            'US-AZ', 'US-CT', 'US-DC', 'US-HI', 'US-KY', 'US-LA', 'US-MA', 'US-NM',
            'US-NY', 'US-PA', 'US-RI', 'US-SC', 'US-SD', 'US-TN', 'US-UT', 'US-VT',
            'US-WA', 'US-WV',
        ];

        $exempt = [
            'US-AR', 'US-CA', 'US-CO', 'US-FL', 'US-GA', 'US-ID', 'US-IL', 'US-IN',
            'US-KS', 'US-ME', 'US-MI', 'US-MN', 'US-MO', 'US-NE', 'US-NV', 'US-NJ',
            'US-NC', 'US-ND', 'US-OK', 'US-VA', 'US-WI', 'US-WY',
        ];

        $overrides = [];

        foreach ($taxable as $state) {
            $overrides[$state.':'.TaxCategory::DigitalService->value] = true;
        }

        foreach ($exempt as $state) {
            $overrides[$state.':'.TaxCategory::DigitalService->value] = false;
        }

        return $overrides;
    }
}
