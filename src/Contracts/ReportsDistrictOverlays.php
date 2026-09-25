<?php

declare(strict_types=1);

namespace Cbox\Tax\Contracts;

use Cbox\Geo\ValueObjects\Jurisdiction;
use DateTimeImmutable;

/**
 * A local-authority resolver that can say which districts drawn over its postal
 * layer might apply to a locality it answered without a point.
 *
 * An optional companion to {@see LocalAuthorityResolver}, like
 * {@see ReportsSplitPostcodes}. Nebraska's Good Life Districts set the state's own
 * rate inside boundaries no ZIP follows. A ZIP+4 in a ZIP one of them reaches is
 * answered as though the address were outside it, which is right outside and wrong
 * inside — the rate source uses this to say so.
 */
interface ReportsDistrictOverlays
{
    /**
     * The districts in force on the date that reach the locality's ZIP and were not
     * tested against a point: each one's authority and the codes it stands in place of.
     * A null authority is a district layer that could not be read at all.
     *
     * @return list<array{authority: ?string, replaces: list<string>}>
     */
    public function districtsUnchecked(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): array;
}
