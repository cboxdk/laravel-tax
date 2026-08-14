<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * A legal statement the invoice must carry, as structure rather than prose.
 *
 * `TaxAssessment::$reason` is an English explanation for an audit trail. It is not
 * an invoice mention, and a caller that prints it as one produces a defective
 * invoice — because several of these are **mandatory wording**, not description.
 *
 * The load-bearing case is reverse charge. Art. 226(11a) of the VAT Directive
 * requires the words "Reverse charge" on the invoice, and the CJEU held in
 * *Luxury Trust Automobil* (C-247/21) that a missing mention **cannot be corrected
 * retroactively** — the supply stays defective. So this cannot be left to the
 * caller to phrase, and it cannot be a boolean either: what has to be printed is a
 * specific sentence, next to a reference to the provision that exempts the supply
 * (Art. 226(11)).
 *
 * `code` is the stable machine key to branch or translate on; `text` is the
 * wording to print if you have no localisation of your own; `reference` names the
 * provision. Only the engine knows which applies, because only it knows why the
 * supply was treated the way it was.
 */
readonly class InvoiceMention
{
    public function __construct(
        /** Stable key — `reverse_charge`, `exempt_certificate`. Translate on this. */
        public string $code,
        /** The wording to print. Mandatory phrasing where the law fixes it. */
        public string $text,
        /** The provision relied on, where one applies. */
        public ?string $reference = null,
    ) {}

    /** The mention as one printable line, reference included where there is one. */
    public function line(): string
    {
        return $this->reference === null ? $this->text : $this->text.' — '.$this->reference;
    }
}
