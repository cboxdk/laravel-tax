<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\AttributionStatus;

/**
 * A member state's answer to who accounts for the tax on one supply by a supplier not
 * established there, with the provision an invoice cites and what is left open.
 */
readonly class Attribution
{
    /**
     * @param  list<UnsettledCondition>  $unsettled  The conditions the supply's facts
     *                                               do not settle, where the status is
     *                                               `Unsettled`.
     */
    public function __construct(
        public AttributionStatus $status = AttributionStatus::NotPublished,
        /** The national provision, e.g. `§ 13b UStG`, where the rule names one. */
        public ?string $citation = null,
        public array $unsettled = [],
    ) {}
}
