<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use Cbox\Tax\Enums\TaxClass;
use Cbox\Tax\Enums\TaxClassGroup;

/**
 * What a {@see TaxClass} means, in two vocabularies at once.
 *
 * `name` is what a merchant reads when choosing. It has to be answerable by
 * someone who sells the thing, not by someone who reads tax schedules — "Footwear"
 * rather than "Annex III point 6", "Hotel and holiday accommodation" rather than
 * "ACCOMMODATION". A taxonomy nobody can navigate is a taxonomy everybody leaves
 * on the default.
 *
 * The anchors are the other vocabulary, and they are what make the class checkable
 * by someone who was not there when it was written:
 *
 *  - `annexIII` — the point of Annex III to Directive 2006/112/EC that permits a
 *    reduced rate for this kind of supply. Null where no point covers it, which is
 *    itself information: it means the standard rate is the only lawful one in the
 *    EU, and a class with no Annex III point should never resolve to a band.
 *  - `cnPrefixes` — the Combined Nomenclature headings the class covers, for goods.
 *    TEDB scopes its own rates by CN, so this is the source's own language rather
 *    than ours. Empty for services, which CN does not describe.
 *
 * Without the anchors this list would be one person's opinion about how commerce
 * divides up. With them it is a claim anyone can check against the Directive.
 */
readonly class TaxClassInfo
{
    /**
     * @param  list<string>  $cnPrefixes
     * @param  list<string>  $examples  concrete goods a merchant will recognise
     */
    public function __construct(
        public TaxClassGroup $group,
        public string $name,
        public ?int $annexIII = null,
        public array $cnPrefixes = [],
        public array $examples = [],
    ) {}

    /** Whether EU law permits a reduced rate for this kind of supply at all. */
    public function mayBeReducedInEu(): bool
    {
        return $this->annexIII !== null;
    }

    public function isGoods(): bool
    {
        return $this->cnPrefixes !== [];
    }
}
