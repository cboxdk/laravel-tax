<?php

declare(strict_types=1);

namespace Cbox\Tax\Testing;

use Cbox\Geo\ValueObjects\Jurisdiction;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use DateTimeImmutable;

/**
 * A {@see LocalAuthorityResolver} you script, for testing a host adapter's wiring
 * without calling a state portal.
 *
 * Answers are keyed by the jurisdiction's string form, so a test can distinguish a
 * Denver address from a Boulder one. Anything unscripted DEFERS — which is what a
 * real resolver does outside the states it covers, so a test that forgets to script
 * an address gets the shipped behaviour rather than an empty stack.
 *
 * Recording every call is deliberate. The mistake this fake exists to catch is a
 * resolver that is bound but never consulted — the assessment still comes out with
 * a plausible number, just the state share, and nothing in the result says the
 * lookup never happened.
 */
class FakeLocalAuthorityResolver implements LocalAuthorityResolver
{
    /** @var array<string, list<string>|null> */
    private array $answers = [];

    /** @var list<array{jurisdiction: string, at: ?string}> */
    public array $calls = [];

    /**
     * Script the authorities for a jurisdiction. Pass `[]` for the positive "no
     * local authority taxes here", and null to make it defer explicitly.
     *
     * @param  list<string>|null  $codes
     */
    public function resolve(Jurisdiction $jurisdiction, ?array $codes): self
    {
        $this->answers[self::key($jurisdiction)] = $codes;

        return $this;
    }

    /** @return list<string>|null */
    public function authoritiesFor(Jurisdiction $jurisdiction, ?DateTimeImmutable $at = null): ?array
    {
        $this->calls[] = [
            'jurisdiction' => self::key($jurisdiction),
            'at' => $at?->format('Y-m-d'),
        ];

        return $this->answers[self::key($jurisdiction)] ?? null;
    }

    /**
     * The scripting key: country, subdivision and locality.
     *
     * {@see Jurisdiction} is not `Stringable`, and building the key by hand is the
     * better outcome anyway — it makes explicit that two addresses in the same state
     * are the SAME key unless they carry different localities. A fake that silently
     * conflated them would let a test pass while a real resolver, which sees the
     * whole address, behaved differently.
     */
    private static function key(Jurisdiction $jurisdiction): string
    {
        return implode('|', [
            $jurisdiction->country->value,
            $jurisdiction->subdivision->value ?? '-',
            $jurisdiction->locality === null ? '-' : (string) $jurisdiction->locality,
        ]);
    }

    /** Whether the resolver was consulted for a jurisdiction at all. */
    public function wasConsultedFor(Jurisdiction $jurisdiction): bool
    {
        foreach ($this->calls as $call) {
            if ($call['jurisdiction'] === self::key($jurisdiction)) {
                return true;
            }
        }

        return false;
    }
}
