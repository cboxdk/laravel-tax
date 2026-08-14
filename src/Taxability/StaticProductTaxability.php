<?php

declare(strict_types=1);

namespace Cbox\Tax\Taxability;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Exceptions\UnresolvedProductTaxability;
use Cbox\Tax\ValueObjects\TaxDetermination;
use DateTimeImmutable;

/**
 * A taxability matrix backed by a static override map.
 *
 * `Standard` — general tangible goods — is taxable by default, and only that. It
 * is the one category where a default is honest: every US sales-tax state taxes
 * general merchandise, so the rule states the law rather than guessing at it.
 *
 * **Every other US category is explicit-only.** Groceries, candy, clothing,
 * prewritten software, supplements, professional and AI-API services are each
 * taxable in some states and exempt in others, and the answer cannot be inferred
 * from the goods rule. An unconfigured pair therefore raises
 * {@see UnresolvedProductTaxability} rather than defaulting to taxable — which
 * would silently over-collect from a consumer, the failure mode that produces
 * refund claims. Deny-by-default cuts both ways, and this is the direction it
 * cuts here.
 *
 * Outside the US the goods rule stands: national VAT/GST regimes tax at the
 * standard rate and consult this seam only incidentally.
 *
 * Production should bind a matrix sourced from an authoritative feed (e.g. the
 * SST taxability matrices and state/local guidance), because taxability is DATA
 * that changes.
 */
readonly class StaticProductTaxability implements ProductTaxability
{
    /** @var array<string, bool> */
    private array $overrides;

    /**
     * @param  array<string, bool>  $overrides  Key "<jurisdiction>:<class>" => taxable,
     *                                          e.g. "US-CA:digital_service" => false.
     */
    public function __construct(array $overrides = [])
    {
        $this->overrides = self::normalise($overrides);
    }

    /**
     * Accept override keys written against the superseded {@see TaxCategory}.
     *
     * Seventeen of its twenty-five values changed name when the taxonomy was
     * rebuilt — `grocery` became `groceries`, `books` became `book` — and an
     * override is exactly the kind of thing an operator wrote once, put in a config
     * file and forgot. Left to break, the failure is the worst shape available: the
     * key stops matching, the override silently stops applying, and the engine
     * refuses or charges the default on a category somebody had deliberately
     * configured.
     *
     * So a legacy key is translated rather than ignored. A key already written
     * against a class is left exactly as it is, and one that matches neither is
     * kept verbatim so it fails visibly rather than being quietly dropped.
     *
     * @param  array<string, bool>  $overrides
     * @return array<string, bool>
     */
    private static function normalise(array $overrides): array
    {
        $normalised = [];

        foreach ($overrides as $key => $taxable) {
            $separator = strrpos($key, ':');

            if ($separator === false) {
                $normalised[$key] = $taxable;

                continue;
            }

            $where = substr($key, 0, $separator);
            $what = substr($key, $separator + 1);

            $class = TaxClass::tryFrom($what) ?? TaxCategory::tryFrom($what)?->toClass();

            $normalised[$where.':'.($class === null ? $what : $class->value)] = $taxable;
        }

        return $normalised;
    }

    /**
     * `$at` is accepted and ignored, and that is the honest behaviour rather than a
     * gap: this is a hand-maintained snapshot with no dated windows in it, so it has
     * nothing to say about how a category was treated on any particular day. It
     * answers the same for every date because it only knows one.
     *
     * A host that needs historical taxability binds a dated source — the shipped
     * `UsTaxDatasetTaxability` reads the dataset's dated windows and does honour it.
     */
    public function determine(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        $key = $where.':'.$category->value;

        if (array_key_exists($key, $this->overrides)) {
            // A hand-maintained map states taxable or exempt and nothing finer.
            // It carries no thresholds and no reduced rates, so it never returns
            // one — a snapshot that invented nuance would be worse than a coarse
            // snapshot that admits to being coarse.
            return $this->overrides[$key] ? TaxDetermination::taxable() : TaxDetermination::exempt();
        }

        if ($jurisdiction->country->value === 'US' && $category !== TaxClass::GeneralGoods) {
            throw UnresolvedProductTaxability::for($jurisdiction, $category);
        }

        return TaxDetermination::taxable();
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
            $overrides[$state.':'.TaxClass::DigitalService->value] = true;
        }

        foreach ($exempt as $state) {
            $overrides[$state.':'.TaxClass::DigitalService->value] = false;
        }

        return $overrides;
    }
}
