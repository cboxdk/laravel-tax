<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Contracts\AddressGeocoder;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Cbox\Tax\Nexus\StaticNexusThresholds;
use Cbox\Tax\RateSource\CachingTaxRateSource;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use Cbox\Tax\RateSource\IbericodeVatRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\RateSource\TedbRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Returns\DefaultReturnAggregator;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Validators\AbnLookupValidator;
use Cbox\Tax\Validators\DispatchingVatIdValidator;
use Cbox\Tax\Validators\HmrcVatValidator;
use Cbox\Tax\Validators\ViesValidator;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

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

            if ($sources === []) {
                return $static;
            }

            $sources[] = $static;

            return new ChainTaxRateSource($sources);
        });

        $this->app->singleton(ProductTaxability::class, static fn (): StaticProductTaxability => new StaticProductTaxability(StaticProductTaxability::unitedStatesSaas()));

        $this->app->singleton(NexusThresholds::class, static fn (): StaticNexusThresholds => new StaticNexusThresholds);

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

        $this->app->singleton(AddressGeocoder::class, static fn (Application $app): GeocodioGeocoder => new GeocodioGeocoder(
            $app->make(Factory::class),
            $app->make(JurisdictionRepository::class),
            $key,
            $baseUrl,
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
