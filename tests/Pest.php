<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\CommerceExtensions\Actions\AddEndpoint;
use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterEventName;
use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterExtension;
use Liberu\Ecommerce\CommerceExtensions\Actions\Subscribe;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Api\Tests\Doubles\FakeTransport;
use Liberu\Ecommerce\CommerceExtensions\Api\Tests\Fixtures\ApiActor;
use Liberu\Ecommerce\CommerceExtensions\Api\Tests\TestCase;

/*
 * `Unit` gets the same case as `Feature`: the unit suite asserts on the route
 * table, the failure map and the OpenAPI document, which are properties of a
 * booted application rather than of a class in isolation.
 */
uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/*
 * Nothing is bound. Several tests here claim that an unbound transport refuses,
 * and a binding leaked from an earlier test would prove the opposite.
 */
uses()->beforeEach(function (): void {
    Config::set('commerce_extensions.transport', null);
    Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00'));
})->in('Feature', 'Unit');

/**
 * A credential for one merchant, carrying exactly the abilities named.
 *
 * The default is reading only. A test that writes, raises or delivers has to
 * say so, which is the same statement the runbook asks a host to make when it
 * issues a token.
 *
 * @param  list<string>  $abilities
 */
function actor(array $abilities = [Scope::READ], ?string $tenant = 'merchant-a'): ApiActor
{
    return ApiActor::query()->create(['team_id' => $tenant, 'abilities' => $abilities]);
}

/** A credential that can do everything, for the tests that are not about abilities. */
function operator(?string $tenant = 'merchant-a'): ApiActor
{
    return actor(Scope::all(), $tenant);
}

/** The base path every route on this surface hangs off. */
function api(string $path = ''): string
{
    return rtrim('/api/commerce-extensions/'.ltrim($path, '/'), '/');
}

/**
 * A merchant with one live extension, one endpoint and one subscription.
 *
 * @return array{extension_id: int, endpoint_id: int, secret: string}
 */
function seeded(
    string $tenant = 'merchant-a',
    string $eventName = 'order.paid',
    string $url = 'https://203.0.113.10/hook',
    string $extensionRef = 'partner-1',
): array {
    $extension = (new RegisterExtension())($tenant, $extensionRef, 'A Partner');
    (new RegisterEventName())($tenant, $eventName);
    $issued = (new AddEndpoint())($tenant, $extension->id, $url);
    (new Subscribe())($tenant, $issued->endpointId, $eventName);

    return [
        'extension_id' => $extension->id,
        'endpoint_id' => $issued->endpointId,
        'secret' => $issued->secret,
    ];
}

function bindTransport(?FakeTransport $transport = null): FakeTransport
{
    $transport ??= new FakeTransport();
    Config::set('commerce_extensions.transport', $transport);

    return $transport;
}
