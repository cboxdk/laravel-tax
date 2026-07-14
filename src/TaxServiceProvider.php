<?php

declare(strict_types=1);

namespace Cbox\Tax;

use Cbox\Tax\Contracts\RegimeRegistry;
use Cbox\Tax\Contracts\TaxCalculator;
use Cbox\Tax\Contracts\TaxRateSource;
use Cbox\Tax\RateSource\StaticTaxRateSource;
use Cbox\Tax\Registry\DefaultRegimeRegistry;
use Illuminate\Contracts\Foundation\Application;
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

        $this->app->singleton(RegimeRegistry::class, static fn (): DefaultRegimeRegistry => DefaultRegimeRegistry::withDefaults());

        $this->app->singleton(TaxCalculator::class, static function (Application $app): DefaultTaxCalculator {
            return new DefaultTaxCalculator(
                $app->make(RegimeRegistry::class),
                $app->make(TaxRateSource::class),
            );
        });
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
