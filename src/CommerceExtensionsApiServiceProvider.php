<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;

/**
 * Registers this package's HTTP surface and nothing else.
 *
 * It binds no seam. `commerce_extensions.transport` stays unbound: a default
 * transport reporting "delivered" would settle deliveries nothing ever sent.
 */
final class CommerceExtensionsApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerce-extensions-api.php', 'commerce-extensions-api');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/commerce-extensions-api.php' => $this->app->configPath('commerce-extensions-api.php'),
        ], 'commerce-extensions-api-config');

        Route::group([
            'prefix' => Cast::str(Config::get('commerce-extensions-api.route.prefix')) ?: 'api/commerce-extensions',
            'middleware' => (array) Config::get('commerce-extensions-api.route.middleware', []),
            'domain' => Config::get('commerce-extensions-api.route.domain'),
            'as' => 'commerce-extensions-api.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/api.php'));
    }
}
