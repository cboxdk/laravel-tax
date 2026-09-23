<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Cbox\Geo\Contracts\JurisdictionRepository;
use Cbox\Tax\Catalogue\CatalogueAudit;
use Cbox\Tax\Catalogue\EmptyProductCatalogue;
use Cbox\Tax\Charges\NoFlatCharges;
use Cbox\Tax\Charges\NoOrderFlatCharges;
use Cbox\Tax\Contracts\AddressGeocoder;
use Cbox\Tax\Contracts\DeliveryRules;
use Cbox\Tax\Contracts\EuTerritories;
use Cbox\Tax\Contracts\FlatChargeSource;
use Cbox\Tax\Contracts\LocalAuthorityResolver;
use Cbox\Tax\Contracts\MarketplaceRules;
use Cbox\Tax\Contracts\NexusThresholds;
use Cbox\Tax\Contracts\OrderFlatChargeSource;
use Cbox\Tax\Contracts\OrderTaxCalculator;
use Cbox\Tax\Contracts\ProductCatalogue;
use Cbox\Tax\Contracts\ProductTaxability;
use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\ReturnAggregator;
use Cbox\Tax\Contracts\RoundingRules;
use Cbox\Tax\Contracts\SourcingRules;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\Contracts\UsTaxFacts;
use Cbox\Tax\Contracts\VatIdValidator;
use Cbox\Tax\Geocoder\GeocodioGeocoder;
use Cbox\Tax\RateSource\DefersLocalAuthorities;
use Cbox\Tax\Register\Compile\SectionFetcher;
use Cbox\Tax\Register\Console\ActivateCommand;
use Cbox\Tax\Register\Console\PruneCommand;
use Cbox\Tax\Register\Console\StatusCommand;
use Cbox\Tax\Register\Console\SyncCommand;
use Cbox\Tax\Register\Console\VerifyCommand;
use Cbox\Tax\Register\Reader\RateResolver;
use Cbox\Tax\Register\Reader\RegisterDataset;
use Cbox\Tax\Register\Reader\Shape;
use Cbox\Tax\Register\Sources\RegisterBoundaries;
use Cbox\Tax\Register\Sources\RegisterDelivery;
use Cbox\Tax\Register\Sources\RegisterMarketplaceRules;
use Cbox\Tax\Register\Sources\RegisterNexus;
use Cbox\Tax\Register\Sources\RegisterRateSource;
use Cbox\Tax\Register\Sources\RegisterRounding;
use Cbox\Tax\Register\Sources\RegisterSourcing;
use Cbox\Tax\Register\Sources\RegisterTaxability;
use Cbox\Tax\Register\Sources\RegisterUsFacts;
use Cbox\Tax\Register\Store\StoreLayout;
use Cbox\Tax\Register\Store\StorePointer;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Cbox\Tax\Returns\DefaultReturnAggregator;
use Cbox\Tax\Territories\StaticEuTerritories;
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
 * register rate source. Hosts override the rate source — and any regime — by
 * rebinding the contract. The default source requires a synced local store.
 */
class TaxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tax.php', 'tax');

        $this->registerRegister();

        // The register's own boundary artifacts, where a synced store carries them:
        // twenty-four Streamlined states by ZIP+4 and California by polygon. Where it
        // does not, this defers — and deferring is not "no local tax", it is "ask
        // somebody else", which is why the contract distinguishes null from [].
        //
        // A host with better resolution still rebinds this one contract. Colorado's
        // GIS is the standing example: under CRS 39-26-105.2 the hold-harmless
        // attaches to the vendor who used it, so it cannot be obtained on a
        // customer's behalf through somebody else's key.
        $this->app->singleton(LocalAuthorityResolver::class, static function (Application $app): LocalAuthorityResolver {
            $dataset = $app->make(RegisterDataset::class);
            $version = $dataset->version();

            return $version === null
                ? new DefersLocalAuthorities
                : new RegisterBoundaries($app->make(StoreLayout::class), $version, $dataset);
        });

        // Knows no item codes until a host binds its own. An app that never sends an
        // item code behaves exactly as it did before the catalogue existed; one that
        // sends a code with nothing bound gets its lines flagged unmapped, which is
        // the honest report rather than a silent fallback.
        $this->app->singleton(ProductCatalogue::class, static fn (): ProductCatalogue => new EmptyProductCatalogue);

        // Read against the register itself rather than whatever TaxRateSource is bound:
        // the audit asks what the register's CONDITIONS need, which a host's own source
        // in front of it does not publish.
        $this->app->bind(CatalogueAudit::class, static fn (Application $app): CatalogueAudit => new CatalogueAudit(
            $app->make(ProductCatalogue::class),
            new RegisterRateSource($app->make(RegisterDataset::class)),
            $app->make(RegisterDataset::class),
        ));

        // ONE RATE SOURCE. The register covers 80 jurisdictions across eleven
        // regimes; the compiled datasets it replaces reached two, and the static
        // snapshot behind them was a hand-maintained overlay of about fifty national
        // rates. Keeping any of them as a fallback would mean a wrong answer arriving
        // quietly whenever the register had a gap — and a gap is exactly the thing
        // that should be visible.
        //
        // A host that has something better still rebinds this contract, and
        // ChainTaxRateSource is still shipped for putting its own source in front.
        $this->app->singleton(TaxRateSource::class, static fn (Application $app): TaxRateSource => new RegisterRateSource(
            $app->make(RegisterDataset::class),
            new RateResolver,
            // Resolved from the container so a host can bind its own — a state portal
            // it holds credentials for, a commercial adapter, an internal boundary
            // file. The resolver is consulted even without a locality so hosts can
            // supply an address-resolution path beyond the shipped geocoder.
            $app->make(LocalAuthorityResolver::class),
        ));

        $this->app->singleton(ProductTaxability::class, static fn (Application $app): ProductTaxability => new RegisterTaxability(
            $app->make(RegisterDataset::class),
        ));

        $this->app->singleton(NexusThresholds::class, static fn (Application $app): NexusThresholds => new RegisterNexus(
            $app->make(RegisterDataset::class),
        ));

        $this->app->singleton(SourcingRules::class, static fn (Application $app): SourcingRules => new RegisterSourcing(
            $app->make(RegisterDataset::class),
        ));

        $this->app->singleton(UsTaxFacts::class, static fn (Application $app): UsTaxFacts => new RegisterUsFacts(
            $app->make(RegisterDataset::class),
        ));

        $this->app->singleton(RoundingRules::class, static fn (Application $app): RoundingRules => new RegisterRounding(
            $app->make(RegisterDataset::class),
        ));

        $this->app->singleton(DeliveryRules::class, static fn (Application $app): DeliveryRules => new RegisterDelivery(
            $app->make(RegisterDataset::class),
        ));

        $this->app->singleton(RegimeRegistry::class, static fn (Application $app): DefaultRegimeRegistry => DefaultRegimeRegistry::withDefaults(
            $app->make(ProductTaxability::class),
            $app->make(JurisdictionRepository::class),
            $app->make(NexusThresholds::class),
            $app->make(SourcingRules::class),
            $app->make(UsTaxFacts::class),
            $app->make(EuTerritories::class),
            $app->make(RoundingRules::class),
            $app->make(DeliveryRules::class),
        ));

        // No fixed charges are shipped: these levies are per-jurisdiction, move on
        // their own schedule, and no authoritative compilation of them sits behind
        // this package. The seam is bound so a host can supply its own.
        // Two seams, because the two levies are genuinely different: one attaches to
        // a supply, the other to a delivery however many lines it has. Colorado's
        // Retail Delivery Fee is the second kind, and charging it through the first
        // billed a two-line order twice for one delivery.
        $this->app->singleton(EuTerritories::class, static fn (Application $app): EuTerritories => new StaticEuTerritories($app->make(RegisterDataset::class)));

        $this->app->singleton(FlatChargeSource::class, static fn (): FlatChargeSource => new NoFlatCharges);
        $this->app->singleton(OrderFlatChargeSource::class, static fn (): OrderFlatChargeSource => new NoOrderFlatCharges);

        $this->app->singleton(TaxCalculator::class, static function (Application $app): DefaultTaxCalculator {
            return new DefaultTaxCalculator(
                $app->make(RegimeRegistry::class),
                $app->make(TaxRateSource::class),
                $app->make(FlatChargeSource::class),
                $app->make(OrderFlatChargeSource::class),
                $app->make(ProductCatalogue::class),
                $app->make(MarketplaceRules::class),
                $app->make(JurisdictionRepository::class),
            );
        });

        $this->app->singleton(MarketplaceRules::class, static fn (Application $app): MarketplaceRules => new RegisterMarketplaceRules($app->make(RegisterDataset::class)));

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
     * The register: where it lives, how it is reached, and the commands that fill it.
     *
     * Everything here is a singleton because the dataset pins the live version for
     * its own lifetime — two lines of one order have to be priced by the same
     * register, or the totals reconcile with neither.
     */
    private function registerRegister(): void
    {
        $this->app->singleton(StoreLayout::class, static function (Application $app): StoreLayout {
            $configured = $app->make(Config::class)->get('tax.register.store');

            return new StoreLayout(
                is_string($configured) && $configured !== ''
                    ? $configured
                    : $app->storagePath('app/cbox-tax/register'),
            );
        });

        $this->app->singleton(StorePointer::class, static fn (Application $app): StorePointer => new StorePointer(
            $app->make(StoreLayout::class),
        ));

        $this->app->singleton(SectionFetcher::class, static function (Application $app): SectionFetcher {
            $url = $app->make(Config::class)->get('tax.register.url');

            return new SectionFetcher(
                $app->make(Factory::class),
                is_string($url) && $url !== '' ? $url : 'https://data.cboxtax.com',
            );
        });

        $this->app->singleton(RegisterDataset::class, static fn (Application $app): RegisterDataset => new RegisterDataset(
            $app->make(StoreLayout::class),
            $app->make(StorePointer::class),
            Shape::text($app->make(Config::class)->get('tax.register.version')),
        ));
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
        $rooftop = $config->get('tax.geocodio.rooftop') === true;

        $this->app->singleton(AddressGeocoder::class, static fn (Application $app): GeocodioGeocoder => new GeocodioGeocoder(
            $app->make(Factory::class),
            $app->make(JurisdictionRepository::class),
            $key,
            $baseUrl,
            $rooftop,
            $app->make(RegisterDataset::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tax.php' => $this->app->configPath('tax.php'),
            ], 'tax-config');

            $this->commands([
                SyncCommand::class,
                StatusCommand::class,
                ActivateCommand::class,
                VerifyCommand::class,
                PruneCommand::class,
            ]);
        }
    }
}
