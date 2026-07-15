<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Contracts\AddressGeocoder;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Returns\DefaultReturnAggregator;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Validators\AbnLookupValidator;
use Cbox\Tax\Validators\DispatchingVatIdValidator;
use Cbox\Tax\Validators\HmrcVatValidator;
use Cbox\Tax\Validators\ViesValidator;
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

        $this->app->singleton(TaxRateSource::class, static fn (): StaticTaxRateSource => new StaticTaxRateSource);

        $this->app->singleton(ProductTaxability::class, static fn (): StaticProductTaxability => new StaticProductTaxability);

        $this->app->singleton(RegimeRegistry::class, static function (Application $app): DefaultRegimeRegistry {
            return DefaultRegimeRegistry::withDefaults($app->make(ProductTaxability::class));
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
