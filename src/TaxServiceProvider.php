<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Contracts\AddressGeocoder;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Cbox\Tax\Nexus\StaticNexusThresholds;
use Cbox\Tax\Nexus\UsTaxDatasetNexus;
use Cbox\Tax\RateSource\CachingTaxRateSource;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use Cbox\Tax\RateSource\IbericodeVatRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\RateSource\TedbRateSource;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Returns\DefaultReturnAggregator;
use Cbox\Tax\Sourcing\UsTaxDatasetSourcing;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\UsTaxData\UsTaxDataset;
use Cbox\Tax\Validators\AbnLookupValidator;
use Cbox\Tax\Validators\DispatchingVatIdValidator;
use Cbox\Tax\Validators\HmrcVatValidator;
use Cbox\Tax\Validators\ViesValidator;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Package entry point. Binds the engine, the shipped regime registry and a default
 * (static) rate source. Hosts override the rate source — and any regime — by
 * rebinding the contract; nothing forces a migration or external service.
 */
class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tax.php', 'tax');

        $this->app->singleton(TaxRateSource::class, static function (Application $app): TaxRateSource {
            $static = new StaticTaxRateSource;

            // Authoritative live feeds, tried before the static snapshot. Each only
            // activates when an operator configures it; unconfigured, the static
            // snapshot stays the zero-config default. Deny-by-default is preserved:
            // if no source has a rate, the composed source returns null and the
            // engine denies rather than guessing.
            $sources = [];

            $euVatFeed = self::euVatFeedSource($app);

            if ($euVatFeed !== null) {
                $sources[] = $euVatFeed;
            }

            $tedb = $app->make(Config::class)->get('tax.tedb.url');

            if (is_string($tedb) && $tedb !== '') {
                $sources[] = new TedbRateSource($app->make(Factory::class), $tedb);
            }

            // The US dataset owns US rates (the static snapshot carries none). It is
            // US-only, so it returns null elsewhere and the chain falls through.
            $dataset = self::usTaxDataset($app);

            if ($dataset !== null) {
                $sources[] = new UsTaxDatasetRateSource($dataset);
            }

            if ($sources === []) {
                return $static;
            }

            $sources[] = $static;

            return new ChainTaxRateSource($sources);
        });

        // US taxability/nexus/sourcing come from the dataset when enabled (the
        // default), replacing the hardcoded static US tables; the static matrix
        // stays the fallback for non-US and for US pairs the dataset leaves
        // undetermined. Disabled, the shipped static US snapshot is used.
        $this->app->singleton(ProductTaxability::class, static function (Application $app): ProductTaxability {
            $dataset = self::usTaxDataset($app);

            return $dataset !== null
                ? new UsTaxDatasetTaxability($dataset, new StaticProductTaxability)
                : new StaticProductTaxability(StaticProductTaxability::unitedStatesSaas());
        });

        $this->app->singleton(NexusThresholds::class, static function (Application $app): NexusThresholds {
            $dataset = self::usTaxDataset($app);

            return $dataset !== null ? new UsTaxDatasetNexus($dataset) : new StaticNexusThresholds;
        });

        // Intrastate sourcing is a dataset-only plane (no static equivalent shipped):
        // bound when the dataset is enabled, left unbound otherwise (deny-by-default).
        $this->app->singleton(SourcingRules::class, static function (Application $app): SourcingRules {
            $dataset = self::usTaxDataset($app);

            if ($dataset === null) {
                throw new RuntimeException('Intrastate sourcing requires the us-tax-data dataset (tax.us_tax_data.enabled).');
            }

            return new UsTaxDatasetSourcing($dataset);
        });

        $this->app->singleton(RegimeRegistry::class, static function (Application $app): DefaultRegimeRegistry {
            return DefaultRegimeRegistry::withDefaults(
                $app->make(ProductTaxability::class),
                $app->make(JurisdictionRepository::class),
                $app->make(NexusThresholds::class),
            );
        });

        $this->app->singleton(TaxCalculator::class, static function (Application $app): DefaultTaxCalculator {
            return new DefaultTaxCalculator(
                $app->make(RegimeRegistry::class),
                $app->make(TaxRateSource::class),
            );
        });

        $this->app->singleton(ReturnAggregator::class, static fn (): DefaultReturnAggregator => new DefaultReturnAggregator);

        $this->registerGeocoder();
        $this->registerVatIdValidator();
    }

    /**
     * Build the shared us-tax-data loader when enabled (the default), reading its
     * config-driven location. The loader caches fetched sections itself, so it is
     * shared across the rate/taxability/nexus/sourcing bindings. Returns null when
     * the dataset is disabled, so those bindings fall back to the static snapshot.
     */
    private static function usTaxDataset(Application $app): ?UsTaxDataset
    {
        $config = $app->make(Config::class);

        if ($config->get('tax.us_tax_data.enabled') !== true) {
            return null;
        }

        $location = $config->get('tax.us_tax_data.location');

        if (! is_string($location) || $location === '') {
            return null;
        }

        $ttl = $config->get('tax.us_tax_data.ttl');

        return new UsTaxDataset(
            $app->make(Factory::class),
            $app->make(Cache::class),
            $location,
            is_int($ttl) ? $ttl : 86400,
        );
    }

    /**
     * Build the EU VAT live feed (the MIT-licensed ibericode/vat-rates dataset)
     * when it is enabled, wrapped in the request cache. Returns `null` when the
     * feed is disabled (the default) so the static snapshot stays the zero-config
     * default. A URL source is cached to avoid a request per lookup; a local file
     * path is read directly.
     */
    private static function euVatFeedSource(Application $app): ?TaxRateSource
    {
        $config = $app->make(Config::class);

        if ($config->get('tax.eu_vat.enabled') !== true) {
            return null;
        }

        $url = $config->get('tax.eu_vat.url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        $feed = new IbericodeVatRateSource($app->make(Factory::class), $url);

        $isRemote = str_starts_with($url, 'http://') || str_starts_with($url, 'https://');

        if (! $isRemote) {
            return $feed;
        }

        return new CachingTaxRateSource($feed, $app->make(Cache::class));
    }

    /**
     * Bind the VAT-ID validator to VIES (EU) + HMRC (UK), adding ABN Lookup (AU)
     * only when a GUID is configured.
     */
    private function registerVatIdValidator(): void
    {
        $this->app->singleton(VatIdValidator::class, static function (Application $app): DispatchingVatIdValidator {
            $http = $app->make(Factory::class);

            $validators = [new ViesValidator($http), new HmrcVatValidator($http)];

            $guid = $app->make(Config::class)->get('tax.vat_id.abn_guid');

            if (is_string($guid) && $guid !== '') {
                $validators[] = new AbnLookupValidator($http, $guid);
            }

            return new DispatchingVatIdValidator($validators);
        });
    }

    /**
     * Bind the Geocodio address geocoder only when an API key is configured.
     * Without one the AddressGeocoder contract stays unbound — deny-by-default.
     */
    private function registerGeocoder(): void
    {
        $config = $this->app->make(Config::class);
        $key = $config->get('tax.geocodio.key');

        if (! is_string($key) || $key === '') {
            return;
        }

        $baseUrl = $config->get('tax.geocodio.base_url');
        $baseUrl = is_string($baseUrl) ? $baseUrl : 'https://api.geocod.io/v1.7';

        // Rooftop county-FIPS capture is opt-in (partial; see tax.us_tax_data.rooftop).
        $rooftop = $config->get('tax.us_tax_data.rooftop') === true;

        $this->app->singleton(AddressGeocoder::class, static fn (Application $app): GeocodioGeocoder => new GeocodioGeocoder(
            $app->make(Factory::class),
            $app->make(JurisdictionRepository::class),
            $key,
            $baseUrl,
            $rooftop,
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tax.php' => $this->app->configPath('tax.php'),
            ], 'tax-config');
        }
    }
}
