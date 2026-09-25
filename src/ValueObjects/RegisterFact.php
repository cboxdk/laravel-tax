<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

/**
 * One of the register's named facts, as the question a person answers.
 *
 * `product.isConfectionery` is a key; "Is this confectionery, such as sweets, candy,
 * chocolate or chewing gum?" is what a product form shows. The subject says WHERE the
 * answer belongs: a `product` fact is answered once, in the catalogue, and is the same
 * in every sale and every market; a `sale`, `recipient`, `use` or `evidence` fact is
 * answered per sale; a `seller` fact once per seller. Only product facts belong on a
 * product.
 */
readonly class RegisterFact
{
    /**
     * @param  list<string>  $values  the allowed answers, for an enum fact
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $subject,
        public string $askedPer,
        public string $question,
        public ?string $note = null,
        public array $values = [],
    ) {}

    /** Answered once per product, in the catalogue, for every market. */
    public function isAboutTheProduct(): bool
    {
        return $this->subject === 'product';
    }
}
