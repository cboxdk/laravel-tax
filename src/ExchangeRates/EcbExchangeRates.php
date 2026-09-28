<?php

declare(strict_types=1);

namespace Cbox\Tax\ExchangeRates;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Cbox\Tax\Contracts\ExchangeRates;
use Cbox\Tax\ValueObjects\ExchangeRate;
use DateInterval;
use DateTimeImmutable;
use Throwable;

/**
 * The European Central Bank's euro reference rates, read from the files
 * `tax:fx:sync` writes — one per year, so a lookup reads a few kilobytes.
 *
 * The ECB publishes one rate per currency per TARGET business day, as units per euro.
 * A rate between two other currencies is read through the euro: USD→DKK is DKK per
 * euro over USD per euro. On a day with no publication — a weekend, a TARGET holiday
 * — the latest rate before it is the one in force, as Art. 91 has it; but not one
 * older than a week, because that is a store nobody has synced, not a holiday.
 */
class EcbExchangeRates implements ExchangeRates
{
    public const string SOURCE = 'ecb';

    /** The longest gap between two ECB publications a lookup will bridge. */
    private const int LOOKBACK_DAYS = 7;

    /** @var array<string, array<string, array<string, string>>> */
    private array $years = [];

    public function __construct(private readonly string $directory) {}

    public function rate(string $from, string $to, DateTimeImmutable $on): ?ExchangeRate
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        for ($back = 0; $back <= self::LOOKBACK_DAYS; $back++) {
            $day = $on->sub(new DateInterval('P'.$back.'D'));
            $rates = $this->year($day->format('Y'))[$day->format('Y-m-d')] ?? null;

            if ($rates === null) {
                continue;
            }

            $perEuroFrom = $from === 'EUR' ? '1' : ($rates[$from] ?? null);
            $perEuroTo = $to === 'EUR' ? '1' : ($rates[$to] ?? null);

            // A publication that lacks one of the currencies is not a gap to bridge:
            // the ECB stopped quoting it (the Russian rouble from 2022), and an older
            // rate would be a figure it no longer stands behind.
            if ($perEuroFrom === null || $perEuroTo === null) {
                return null;
            }

            return new ExchangeRate(
                $from,
                $to,
                BigDecimal::of($perEuroTo)->dividedBy($perEuroFrom, 10, RoundingMode::HalfUp)->strippedOfTrailingZeros(),
                new DateTimeImmutable($day->format('Y-m-d')),
                self::SOURCE,
            );
        }

        return null;
    }

    /** @return array<string, array<string, string>> date => currency => units per euro */
    private function year(string $year): array
    {
        if (isset($this->years[$year])) {
            return $this->years[$year];
        }

        $path = $this->directory.'/'.$year.'.json';
        $rates = [];

        try {
            $raw = is_file($path) ? file_get_contents($path) : false;
            $decoded = $raw === false ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }

        foreach (is_array($decoded) ? $decoded : [] as $date => $byCurrency) {
            if (! is_string($date) || ! is_array($byCurrency)) {
                continue;
            }

            foreach ($byCurrency as $currency => $rate) {
                if (is_string($currency) && is_string($rate) && is_numeric($rate)) {
                    $rates[$date][$currency] = $rate;
                }
            }
        }

        return $this->years[$year] = $rates;
    }
}
