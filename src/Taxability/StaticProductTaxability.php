<?php

declare(strict_types=1);

namespace Cbox\Tax\Taxability;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Exceptions\UnresolvedProductTaxability;

/**
 * A taxability matrix backed by a static override map. Standard goods are taxable
 * by default, but US digital services are explicit-only: SaaS taxability is too
 * state-specific to infer from the standard goods rule.
 *
 * Production should bind a matrix sourced from an authoritative feed (e.g. the
 * SST taxability matrices and state/local guidance), because taxability is DATA
 * that changes.
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

        $key = $where.':'.$category->value;

        if (array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }

        if ($jurisdiction->country->value === 'US' && $category === TaxCategory::DigitalService) {
            throw UnresolvedProductTaxability::for($jurisdiction, $category);
        }

        return true;
    }

    /**
     * Curated per-state SaaS (digital-service) taxability for the United States,
     * keyed `"US-XX:digital_service" => taxable`. Sourced from two authoritative,
     * dated practitioner compilations (TaxJar and Anrok SaaS-by-state guides,
     * retrieved 2026-07-17); only states where BOTH compilations agree on a clear
     * taxable/exempt determination are included. See
     * `docs/coverage/us-saas-taxability.md` for the per-state citations and the
     * states left UNDETERMINED (home-rule-only, conflicting sources, or
     * B2B/B2C-conditional and partial regimes a boolean cannot represent) — those
     * are deliberately ABSENT so an operator must configure them.
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
            // No general statewide sales tax.
            'US-DE', 'US-MT', 'US-NH', 'US-OR',
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
