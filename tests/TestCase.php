<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Liberu\Ecommerce\CommerceExtensions\Api\CommerceExtensionsApiServiceProvider;
use Liberu\Ecommerce\CommerceExtensions\CommerceExtensionsServiceProvider;
use Liberu\PackageTestbench\PackageTestCase;

/**
 * The domain package is named here rather than duplicated into `require-dev`,
 * which would put one package in both `require` and `require-dev` and make
 * `composer validate` warn during Install.
 */
abstract class TestCase extends PackageTestCase
{
    use RefreshDatabase;

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return array_values(array_unique(array_merge(
            [CommerceExtensionsServiceProvider::class, CommerceExtensionsApiServiceProvider::class],
            parent::getPackageProviders($app),
        )));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', Fixtures\ApiActor::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
