<?php

declare(strict_types=1);

namespace Cbox\Tax\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The window a return covers — a month, a quarter, a year.
 *
 * Both bounds are INCLUSIVE of their day. A quarter that ended on 31 December has
 * to contain the supplies made on 31 December, and an exclusive end date is the
 * classic way to lose them.
 */
readonly class ReturnPeriod
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        /** How the authority names it — "Q4 2026", "2026-10". Free-form on purpose. */
        public ?string $label = null,
    ) {}

    /** A calendar month, 1–12. */
    public static function month(int $year, int $month): self
    {
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        return new self($from, $from->modify('last day of this month'), sprintf('%04d-%02d', $year, $month));
    }

    /** A calendar quarter, 1–4 — the common VAT and sales-tax filing window. */
    public static function quarter(int $year, int $quarter): self
    {
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, ($quarter - 1) * 3 + 1));

        return new self(
            $from,
            $from->modify('+2 months')->modify('last day of this month'),
            sprintf('Q%d %04d', $quarter, $year),
        );
    }

    public static function year(int $year): self
    {
        return new self(
            new DateTimeImmutable(sprintf('%04d-01-01', $year)),
            new DateTimeImmutable(sprintf('%04d-12-31', $year)),
            (string) $year,
        );
    }

    public function covers(DateTimeInterface $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->from->format('Y-m-d') <= $day && $day <= $this->to->format('Y-m-d');
    }

    public function describe(): string
    {
        return $this->label ?? $this->from->format('Y-m-d').' to '.$this->to->format('Y-m-d');
    }
}
