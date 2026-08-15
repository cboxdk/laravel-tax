<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Charges\NoFlatCharges;
use Cbox\Tax\Charges\NoOrderFlatCharges;
use Cbox\Tax\Contracts\AddressGeocoder;
use Cbox\Tax\Contracts\EuTerritories;
use Cbox\Tax\Contracts\FlatChargeSource;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\OrderFlatChargeSource;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\EuTaxData\EuTaxDataset;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Cbox\Tax\Nexus\StaticNexusThresholds;
use Cbox\Tax\Nexus\UsTaxDatasetNexus;
use Cbox\Tax\RateSource\ArcGisRateSource;
use Cbox\Tax\RateSource\ChainTaxRateSource;
use Cbox\Tax\RateSource\DefersLocalAuthorities;
use Cbox\Tax\RateSource\EuTaxDatasetRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\RateSource\TedbSoapRateSource;
use Cbox\Tax\RateSource\UsTaxDatasetRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Returns\DefaultReturnAggregator;
use Cbox\Tax\Sourcing\UsTaxDatasetSourcing;
use Cbox\Tax\Taxability\StaticProductTaxability;
use Cbox\Tax\Taxability\UsTaxDatasetTaxability;
use Cbox\Tax\Territories\StaticEuTerritories;
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

        // The typed US dataset accessor, resolvable for consumers that want dataset METADATA
        // beyond the rate/taxability/nexus/sourcing contracts — notably the curated rate and
        // baseline notes (rateNote()/baselineNote(), the "see … note" caveats in the coverage
        // matrix). Null when the dataset is disabled or unconfigured, so callers null-check
        // (deny-by-default), exactly as the adapters below do.
        $this->app->singleton(UsTaxDataset::class, static fn (Application $app): ?UsTaxDataset => self::usTaxDataset($app));

        // Deferring by default: this package ships credentials for no state portal
        // and will not guess an authority it cannot resolve. A host that has better
        // resolution — Colorado's GIS under its own SUTS key, a commercial adapter,
        // an internal boundary file — rebinds this one contract and the US rate
        // source starts stacking what it returns. See docs/extension-points.
        $this->app->singleton(LocalAuthorityResolver::class, static fn (): LocalAuthorityResolver => new DefersLocalAuthorities);

        $this->app->singleton(TaxRateSource::class, static function (Application $app): TaxRateSource {
            $static = new StaticTaxRateSource;

            // Authoritative live feeds, tried before the static snapshot. Each only
            // activates when an operator configures it; unconfigured, the static
            // snapshot stays the zero-config default. Deny-by-default is preserved:
            // if no source has a rate, the composed source returns null and the
            // engine denies rather than guessing.
            $sources = [];

            $config = $app->make(Config::class);

            // The compiled EU dataset, tried before the live service and before any
            // hand-built export. It carries a dated series, so a back-dated supply is
            // priced with the rate that applied then rather than today's — which no
            // live call can do in one request — and it publishes the source's own
            // ambiguities rather than resolving them silently.
            $euDataset = $config->get('tax.eu_tax_data.location');

            if (is_string($euDataset) && $euDataset !== '') {
                $euTtl = $config->get('tax.eu_tax_data.ttl');

                $sources[] = new EuTaxDatasetRateSource(new EuTaxDataset(
                    $app->make(Factory::class),
                    $app->make(Cache::class),
                    $euDataset,
                    is_int($euTtl) ? $euTtl : 86400,
                ));
            }

            // The live TEDB service is the authoritative EU source and needs no key,
            // so it is tried before a hand-built export. It is cached per country, not
            // per lookup, so enabling it costs one request per country per TTL.
            if ($config->get('tax.tedb.live') === true) {
                $ttl = $config->get('tax.tedb.ttl');

                $sources[] = new TedbSoapRateSource(
                    $app->make(Factory::class),
                    $app->make(Cache::class),
                    is_int($ttl) ? $ttl : 86400,
                );
            }

            // Where a state publishes its own rooftop polygons (CA, NM), a point
            // resolves finer than the dataset's postal index — so it is tried
            // first, and returns null everywhere else.
            if ($config->get('tax.us_tax_data.rooftop') === true) {
                $ttl = $config->get('tax.us_tax_data.ttl');

                $sources[] = new ArcGisRateSource(
                    $app->make(Factory::class),
                    $app->make(Cache::class),
                    is_int($ttl) ? $ttl : 86400,
                );
            }

            // The US dataset owns US rates (the static snapshot carries none). It is
            // US-only, so it returns null elsewhere and the chain falls through.
            $dataset = self::usTaxDataset($app);

            if ($dataset !== null) {
                // Resolved from the container so a host can bind its own — a state
                // portal it holds credentials for, a commercial adapter, an
                // internal boundary file. The default defers on everything, so an
                // app that binds nothing behaves exactly as before.
                $sources[] = new UsTaxDatasetRateSource(
                    $dataset,
                    $app->make(LocalAuthorityResolver::class),
                );
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
            // Sourcing is a dataset-only plane, and its binding refuses outright
            // when the dataset is off. Ask the same question the binding asks
            // rather than resolving it to find out: the regime treats a missing
            // source as "destination everywhere", which is what it did before
            // intrastate sourcing was applied at all.
            $sourcing = self::usTaxDataset($app) !== null ? $app->make(SourcingRules::class) : null;

            return DefaultRegimeRegistry::withDefaults(
                $app->make(ProductTaxability::class),
                $app->make(JurisdictionRepository::class),
                $app->make(NexusThresholds::class),
                $sourcing,
                self::usTaxDataset($app),
            );
        });

        // No fixed charges are shipped: these levies are per-jurisdiction, move on
        // their own schedule, and no authoritative compilation of them sits behind
        // this package. The seam is bound so a host can supply its own.
        // Two seams, because the two levies are genuinely different: one attaches to
        // a supply, the other to a delivery however many lines it has. Colorado's
        // Retail Delivery Fee is the second kind, and charging it through the first
        // billed a two-line order twice for one delivery.
        $this->app->singleton(EuTerritories::class, static fn (): EuTerritories => new StaticEuTerritories);

        $this->app->singleton(FlatChargeSource::class, static fn (): FlatChargeSource => new NoFlatCharges);
        $this->app->singleton(OrderFlatChargeSource::class, static fn (): OrderFlatChargeSource => new NoOrderFlatCharges);

        $this->app->singleton(TaxCalculator::class, static function (Application $app): DefaultTaxCalculator {
            return new DefaultTaxCalculator(
                $app->make(RegimeRegistry::class),
                $app->make(TaxRateSource::class),
                $app->make(FlatChargeSource::class),
                $app->make(OrderFlatChargeSource::class),
            );
        });

        // The shipped calculator assesses documents directly. A host that rebound
        // TaxCalculator to its own engine gets the same capability by fan-out —
        // never the shipped calculator, which would silently bypass their tax logic
        // for multi-line invoices while single supplies still used it.
        $this->app->singleton(OrderTaxCalculator::class, static function (Application $app): OrderTaxCalculator {
            $calculator = $app->make(TaxCalculator::class);

            return $calculator instanceof OrderTaxCalculator
                ? $calculator
                : new FanOutOrderCalculator($calculator);
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
        $baseUrl = is_string($baseUrl) ? $baseUrl : 'https://api.geocod.io/v2';

        // Gates only the paths that resolve BELOW the county line — the ZIP+4 append
        // and the polygon services. County resolution (FL, PA, HI) runs regardless:
        // it needs no append, and in those states the county is the whole local
        // share, so withholding it would just under-charge.
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
