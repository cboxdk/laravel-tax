<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\SourcingMode;

/**
 * How a US state sources an INTRASTATE sale — which jurisdiction's local rate
 * applies when both seller and buyer are in the same state. `mode` is the rule
 * ({@see SourcingMode}); `note` spells out the split for `Mixed` states (e.g. state
 * and county origin-sourced, district destination-sourced).
 *
 * Interstate/remote supplies are destination-sourced everywhere regardless, so this
 * only refines local-rate selection for in-state supplies. It is a DATA seam the
 * host can consult when it resolves the applicable local jurisdiction.
 */
readonly class IntrastateSourcing
{
    public function __construct(
        public SourcingMode $mode,
        public ?string $note = null,
    ) {}
}
