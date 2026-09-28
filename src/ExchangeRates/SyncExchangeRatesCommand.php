<?php

declare(strict_types=1);

namespace Cbox\Tax\ExchangeRates;

use DOMDocument;
use DOMElement;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Fetch the ECB's euro reference rates to local disk, one file per year, for
 * `EcbExchangeRates` to read while pricing — which never makes a network call.
 *
 * The full history since 1999 is one document; it is small (the rates, not the
 * register) and fetching all of it means a back-dated invoice finds its rate. Each
 * year's file is written whole to a temporary name and renamed into place, so a
 * reader sees the old year or the new one, never half of either.
 */
class SyncExchangeRatesCommand extends Command
{
    protected $signature = 'tax:fx:sync';

    protected $description = 'Download the European Central Bank euro reference rates for converting invoice tax';

    public function handle(Http $http, Config $config): int
    {
        $url = $config->get('tax.exchange_rates.url');
        $url = is_string($url) && $url !== '' ? $url : 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml';
        $directory = self::directory($config, $this->laravel->storagePath('app/cbox-tax/fx/ecb'));

        try {
            $response = $http->timeout(60)->retry(2, 1000)->get($url);
        } catch (Throwable $e) {
            $this->error('Could not reach the ECB: '.$e->getMessage());

            return self::FAILURE;
        }

        $byYear = $response->successful() ? self::parse($response->body()) : [];

        if ($byYear === []) {
            $this->error(sprintf('The ECB answered %d with no rates at %s; nothing was written.', $response->status(), $url));

            return self::FAILURE;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('Cannot create '.$directory);

            return self::FAILURE;
        }

        $days = 0;

        foreach ($byYear as $year => $dates) {
            ksort($dates);
            $path = $directory.'/'.$year.'.json';
            file_put_contents($path.'.tmp', json_encode($dates, JSON_THROW_ON_ERROR));
            rename($path.'.tmp', $path);
            $days += count($dates);
        }

        $this->info(sprintf('ECB reference rates: %d publication days in %d year(s), written to %s.', $days, count($byYear), $directory));

        return self::SUCCESS;
    }

    /** Where the rates live: `tax.exchange_rates.store`, else under the app's storage. */
    public static function directory(Config $config, string $default): string
    {
        $configured = $config->get('tax.exchange_rates.store');

        return is_string($configured) && $configured !== '' ? $configured : $default;
    }

    /**
     * The ECB's XML, read into year => date => currency => units per euro.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public static function parse(string $xml): array
    {
        $document = new DOMDocument;

        if ($xml === '' || ! @$document->loadXML($xml, LIBXML_NONET)) {
            return [];
        }

        $years = [];

        foreach ($document->getElementsByTagName('Cube') as $day) {
            $date = $day->getAttribute('time');

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            foreach ($day->childNodes as $quote) {
                if (! $quote instanceof DOMElement) {
                    continue;
                }

                $currency = $quote->getAttribute('currency');
                $rate = $quote->getAttribute('rate');

                if (preg_match('/^[A-Z]{3}$/', $currency) === 1 && is_numeric($rate)) {
                    $years[substr($date, 0, 4)][$date][$currency] = $rate;
                }
            }
        }

        return $years;
    }
}
