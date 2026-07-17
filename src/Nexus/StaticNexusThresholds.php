<?php

declare(strict_types=1);

namespace Cbox\Tax\Nexus;

use Cbox\Geo\ValueObjects\SubdivisionCode;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Enums\NexusCombinator;
use Cbox\Tax\ValueObjects\NexusThreshold;

/**
 * A static table of US state economic-nexus thresholds, sourced from the Sales Tax
 * Institute's *Economic Nexus State Guide* (a dated, authoritative practitioner
 * compilation of each state's post-*Wayfair* remote-seller threshold). See
 * `docs/coverage/us-nexus-thresholds.md` for the source, the retrieval date and
 * the per-state figures.
 *
 * Only states with a sales tax carry a threshold. The four states with no general
 * sales tax (Delaware, Montana, New Hampshire, Oregon) are ABSENT and return
 * `null`. Transaction-count thresholds are being widely repealed; the dollar
 * threshold is the durable trigger, and operators should re-verify the current
 * figures against the state's own guidance before relying on them.
 */
readonly class StaticNexusThresholds implements NexusThresholds
{
    /** @var array<string, NexusThreshold> */
    private array $thresholds;

    /**
     * @param  array<string, NexusThreshold>|null  $thresholds  ISO 3166-2 state code => threshold; null uses the built-in table.
     */
    public function __construct(?array $thresholds = null)
    {
        $this->thresholds = $thresholds ?? self::defaults();
    }

    public function for(SubdivisionCode $state): ?NexusThreshold
    {
        return $this->thresholds[$state->value] ?? null;
    }

    /**
     * Per-state economic-nexus thresholds. Source: Sales Tax Institute, *Economic
     * Nexus State Guide* (retrieved 2026-07-17). Dollar figures are the annual
     * gross-sales trigger; a non-null transaction count and the combinator capture
     * states that also count transactions.
     *
     * @return array<string, NexusThreshold>
     */
    public static function defaults(): array
    {
        $salesOnly = static fn (int $dollars): NexusThreshold => new NexusThreshold($dollars, null, NexusCombinator::SalesOnly);
        $or200 = static fn (): NexusThreshold => new NexusThreshold(100_000, 200, NexusCombinator::SalesOrTransactions);

        return [
            // $500,000 — sales only.
            'US-CA' => $salesOnly(500_000),
            'US-TX' => $salesOnly(500_000),

            // $500,000 AND 100 transactions (both required).
            'US-NY' => new NexusThreshold(500_000, 100, NexusCombinator::SalesAndTransactions),

            // $250,000 — sales only.
            'US-AL' => $salesOnly(250_000),
            'US-MS' => $salesOnly(250_000),

            // $100,000 AND 200 transactions (both required).
            'US-CT' => new NexusThreshold(100_000, 200, NexusCombinator::SalesAndTransactions),

            // $100,000 OR 200 transactions (either trigger). Per the compilation,
            // these states still applied a 200-transaction threshold as of the
            // retrieval date (Kentucky's is scheduled to end 2026-08-01).
            'US-KY' => $or200(),
            'US-MD' => $or200(),
            'US-MI' => $or200(),
            'US-MN' => $or200(),
            'US-NE' => $or200(),
            'US-NV' => $or200(),
            'US-NJ' => $or200(),
            'US-RI' => $or200(),
            'US-VT' => $or200(),

            // $100,000 — sales only (transaction thresholds repealed or never applied).
            'US-AK' => $salesOnly(100_000), // statewide via the Alaska Remote Seller Sales Tax Commission (local taxes).
            'US-AZ' => $salesOnly(100_000),
            'US-AR' => $salesOnly(100_000),
            'US-CO' => $salesOnly(100_000),
            'US-DC' => $salesOnly(100_000),
            'US-FL' => $salesOnly(100_000),
            'US-GA' => $salesOnly(100_000),
            'US-HI' => $salesOnly(100_000),
            'US-ID' => $salesOnly(100_000),
            'US-IL' => $salesOnly(100_000),
            'US-IN' => $salesOnly(100_000),
            'US-IA' => $salesOnly(100_000),
            'US-KS' => $salesOnly(100_000),
            'US-LA' => $salesOnly(100_000),
            'US-ME' => $salesOnly(100_000),
            'US-MA' => $salesOnly(100_000),
            'US-MO' => $salesOnly(100_000),
            'US-NM' => $salesOnly(100_000),
            'US-NC' => $salesOnly(100_000),
            'US-ND' => $salesOnly(100_000),
            'US-OH' => $salesOnly(100_000),
            'US-OK' => $salesOnly(100_000),
            'US-PA' => $salesOnly(100_000),
            'US-SC' => $salesOnly(100_000),
            'US-SD' => $salesOnly(100_000),
            'US-TN' => $salesOnly(100_000),
            'US-UT' => $salesOnly(100_000),
            'US-VA' => $salesOnly(100_000),
            'US-WA' => $salesOnly(100_000),
            'US-WV' => $salesOnly(100_000),
            'US-WI' => $salesOnly(100_000),
            'US-WY' => $salesOnly(100_000),
        ];
    }
}
