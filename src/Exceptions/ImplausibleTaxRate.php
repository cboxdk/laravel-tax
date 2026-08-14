<?php

declare(strict_types=1);

namespace Cbox\Tax\Exceptions;

use Brick\Math\BigDecimal;
use Cbox\Tax\ValueObjects\TaxRate;
use InvalidArgumentException;

/**
 * Raised when a {@see TaxRate} is constructed outside the only range a percentage
 * can occupy, 0–100.
 *
 * The realistic causes are all data faults rather than caller mistakes: a feed
 * publishing a fraction (`0.25`) where the reader expects a percentage or the
 * reverse, a schema change that moves the decimal point, or a poisoned mirror.
 * None of them announce themselves — a negative rate quietly credits tax back on
 * every invoice, and a 725% rate over-collects, both carrying whatever confidence
 * the source claimed for them.
 *
 * A `LogicException` rather than a runtime denial, for the same reason as
 * {@see RateComponentsDoNotReconcile}: this is not a gap in sourced data the
 * engine can honestly refuse over, it is a source producing a number that cannot
 * be a tax rate. Sources should reject the value and return null — letting a
 * composed chain fall through — rather than letting it reach this constructor.
 */
class ImplausibleTaxRate extends InvalidArgumentException
{
    public static function for(BigDecimal $percentage, string $source): self
    {
        return new self(sprintf(
            'Tax rate %s%% from source "%s" is outside the plausible range 0–100%%. Refusing to build a rate from what is almost certainly corrupt or mis-scaled data.',
            $percentage,
            $source,
        ));
    }
}
