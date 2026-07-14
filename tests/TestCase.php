<?php

declare(strict_types=1);

namespace Cbox\Tax\Tests;

use Cbox\Geo\GeoServiceProvider;
use Cbox\Tax\TaxServiceProvider;
use Cbox\Tax\Testing\InteractsWithTax;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithTax;

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [GeoServiceProvider::class, TaxServiceProvider::class];
    }
}
