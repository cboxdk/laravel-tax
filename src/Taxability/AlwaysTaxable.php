<?php

declare(strict_types=1);

namespace Cbox\Tax\Taxability;

use Brick\Money\Money;
use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Enums\TaxCategory;
use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\ValueObjects\TaxDetermination;
use DateTimeImmutable;

/**
 * Everything is taxable. The floor a regime falls to when nobody supplied a
 * taxability source at all.
 *
 * It is not a guess dressed as an answer: general tangible goods are taxable at the
 * standard rate in every sales-tax state and every member state, so a caller who
 * bound nothing is over-charged rather than under-charged, and that is the direction
 * a refund can fix. What it must never do is quietly exempt something — an
 * under-charge is discovered at audit, years later, with interest.
 *
 * The register answers this properly; this exists so
 * {@see DefaultRegimeRegistry::withDefaults()} can be called with
 * nothing, which is how the regimes are unit-tested.
 */
readonly class AlwaysTaxable implements ProductTaxability
{
    /** @var array<string, bool> */
    private array $overrides;

    /**
     * `$overrides` is the seam for a host that knows better about a specific pair,
     * keyed `"<place>:<tax class>"` — `'US-CA:software_prewritten' => false`.
     *
     * Deliberately an override and not a table: a handful of decisions somebody made
     * and can point at, rather than a compilation maintained beside the register.
     *
     * @param  array<string, bool>  $overrides
     */
    public function __construct(array $overrides = [])
    {
        $this->overrides = self::normalise($overrides);
    }

    /**
     * Accept override keys written against the superseded {@see TaxCategory}.
     *
     * Seventeen of its twenty-five values changed name when the taxonomy was rebuilt
     * — `grocery` became `groceries`, `books` became `book` — and an override is
     * exactly the kind of thing an operator wrote once, put in a config file and
     * forgot. Left to break, the failure is the worst shape available: the key stops
     * matching, the override silently stops applying, and a category somebody
     * deliberately configured falls back to the default.
     *
     * A key already written against a class is left exactly as it is, and one that
     * matches neither is kept verbatim so it fails visibly rather than vanishing.
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

    public function determine(
        Jurisdiction $jurisdiction,
        TaxClass $category,
        Money $amount,
        ?DateTimeImmutable $at = null,
    ): TaxDetermination {
        $where = $jurisdiction->subdivision !== null
            ? $jurisdiction->subdivision->value
            : $jurisdiction->country->value;

        $override = $this->overrides[$where.':'.$category->value] ?? null;

        if ($override === false) {
            return TaxDetermination::exempt();
        }

        return TaxDetermination::taxable();
    }
}
