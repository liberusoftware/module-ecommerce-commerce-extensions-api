<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\Controller;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Contracts\DeliveryTransport;

/** @return list<RoutingRoute> */
function moduleRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        static fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/commerce-extensions'),
    ));
}

/** Every route as `METHOD uri`, with Laravel's synthesised HEAD dropped. */
function routeSignatures(): array
{
    return array_map(
        static fn (RoutingRoute $route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri(),
        moduleRoutes(),
    );
}

it('mounts every route under the configured prefix and name', function () {
    expect(moduleRoutes())->toHaveCount(19);

    foreach (moduleRoutes() as $route) {
        expect($route->getName())->toStartWith('commerce-extensions-api.')
            ->and($route->uri())->toStartWith('api/commerce-extensions/');
    }
});

/*
 * `[]`, never null. An empty stack is a host that has not opted in to a
 * middleware; a null stack is Laravel silently substituting one, which is an
 * opt-out nobody wrote down.
 */
it('defaults the group middleware to an empty array rather than to null', function () {
    expect(Config::get('commerce-extensions-api.route.middleware'))->toBe([])
        ->and(Config::get('commerce-extensions-api.route.domain'))->toBeNull()
        ->and(Config::get('commerce-extensions-api.route.prefix'))->toBe('api/commerce-extensions');
});

/*
 * Nothing is bound as a route model. Type-hinting a domain model in a route
 * signature would couple the transport to the domain's storage, and it would
 * fetch the row *before* asserting custody — the one way this surface could
 * start answering 403 where it must answer 404.
 */
it('binds no route model and names only the four references it takes in a path', function () {
    foreach (moduleRoutes() as $route) {
        foreach ($route->signatureParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                expect($type->getName())->not->toContain('CommerceExtensions\\Models');
            }
        }

        foreach ($route->parameterNames() as $name) {
            expect($name)->toBeIn(['extension', 'endpoint', 'delivery', 'eventName'], $route->uri());
        }
    }
});

it('takes no merchant identifier in any path', function () {
    foreach (moduleRoutes() as $route) {
        expect($route->uri())->not->toContain('tenant')
            ->not->toContain('team')
            ->not->toContain('store')
            ->not->toContain('merchant');
    }
});

it('publishes an ability for every routed action, and only abilities it defines', function () {
    foreach (moduleRoutes() as $route) {
        $controller = $route->getController();

        expect($controller)->toBeInstanceOf(Controller::class);

        expect($controller->scopes()[$route->getActionMethod()] ?? null)
            ->toBeIn(Scope::all(), $route->uri().' '.$route->getActionMethod());
    }
});

/*
 * The line that matters runs between configuring where events go and raising
 * one: a service that raises `order.paid` must not be able to point it at a
 * destination of its own, and a worker draining the queue registers nothing.
 */
it('keeps the four abilities apart', function () {
    $byScope = [];

    foreach (moduleRoutes() as $route) {
        $controller = $route->getController();
        $scope = (string) ($controller->scopes()[$route->getActionMethod()] ?? '');
        $byScope[$scope][] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
    }

    foreach (array_keys($byScope) as $scope) {
        sort($byScope[$scope]);
    }

    expect($byScope[Scope::READ])->toBe([
        'GET api/commerce-extensions/deliveries',
        'GET api/commerce-extensions/deliveries/{delivery}',
        'GET api/commerce-extensions/endpoints',
        'GET api/commerce-extensions/event-names',
        'GET api/commerce-extensions/extensions',
    ]);

    expect($byScope[Scope::MANAGE])->toBe([
        'DELETE api/commerce-extensions/endpoints/{endpoint}/previous-secret',
        'DELETE api/commerce-extensions/endpoints/{endpoint}/retirement',
        'DELETE api/commerce-extensions/endpoints/{endpoint}/subscriptions/{eventName}',
        'DELETE api/commerce-extensions/extensions/{extension}/retirement',
        'POST api/commerce-extensions/endpoints',
        'POST api/commerce-extensions/endpoints/{endpoint}/secret-rotations',
        'POST api/commerce-extensions/endpoints/{endpoint}/subscriptions',
        'POST api/commerce-extensions/event-names',
        'POST api/commerce-extensions/extensions',
        'PUT api/commerce-extensions/endpoints/{endpoint}/retirement',
        'PUT api/commerce-extensions/extensions/{extension}/retirement',
    ]);

    expect($byScope[Scope::RAISE])->toBe(['POST api/commerce-extensions/events']);

    expect($byScope[Scope::DELIVER])->toBe([
        'GET api/commerce-extensions/deliveries/due',
        'POST api/commerce-extensions/deliveries/{delivery}/attempts',
    ]);
});

/*
 * Only the controller behind the raise ability reads a caller-supplied subject.
 * Asserted by reflection over the source rather than off the route table,
 * because the property is about what a controller *can* do.
 */
it('reads a caller-supplied subject in exactly one controller', function () {
    $named = [];

    foreach ((array) glob(dirname(__DIR__, 2).'/src/Http/Controllers/*.php') as $file) {
        if (str_contains((string) file_get_contents((string) $file), 'namedSubject(')) {
            $named[] = basename((string) $file);
        }
    }

    sort($named);

    expect($named)->toBe(['Controller.php', 'EventController.php']);
});

/*
 * No throttle of this surface's own. There is no rate limit in this domain to
 * inherit, and one invented in the transport would count client addresses while
 * leaving the panel and the console command unprotected.
 */
it('adds no throttle and therefore promises no wait', function () {
    $middlewares = [];

    foreach (moduleRoutes() as $route) {
        $middlewares = array_merge($middlewares, array_map(
            static fn (mixed $middleware): string => is_string($middleware) ? $middleware : '',
            $route->gatherMiddleware(),
        ));
    }

    expect($middlewares)->toBe([]);
});

it('publishes its config for a host to override', function () {
    expect(file_exists(dirname(__DIR__, 2).'/config/commerce-extensions-api.php'))->toBeTrue()
        ->and(Config::get('commerce-extensions-api.actor.tenant_attribute'))->toBe('team_id')
        ->and(Config::get('commerce-extensions-api.listing.default_per_page'))->toBe(50)
        ->and(Config::get('commerce-extensions-api.listing.max_per_page'))->toBe(200)
        ->and(Config::get('commerce-extensions-api.due.default_limit'))->toBe(100)
        ->and(Config::get('commerce-extensions-api.due.max_limit'))->toBe(500)
        ->and(Config::get('commerce-extensions-api.payload.max_bytes'))->toBe(65535);
});

/*
 * The transport stays unbound. A default answering "delivered" would settle
 * deliveries nothing ever sent, which is worse than the refusal an unbound seam
 * records.
 */
it('binds no transport', function () {
    expect(Config::get('commerce_extensions.transport'))->toBeNull()
        ->and(app()->bound(DeliveryTransport::class))->toBeFalse();
});

/*
 * Two absences that are decisions rather than oversights. The domain's export
 * and erasure span every merchant on the deployment; this surface holds one
 * merchant's credential, so publishing either would let that merchant read
 * another's payloads or destroy another's log. Retention is a scheduled command.
 */
it('publishes no privacy and no retention endpoint, and says why', function () {
    foreach (routeSignatures() as $signature) {
        expect($signature)->not->toContain('subject-record')
            ->not->toContain('erasure')
            ->not->toContain('prune')
            ->not->toContain('retention');
    }

    expect(file_get_contents(dirname(__DIR__, 2).'/docs/domain.md'))
        ->toContain('ExportSubjectRecordAcrossTenants')
        ->toContain('ForgetSubjectAcrossTenants')
        ->toContain('commerce-extensions:prune-deliveries');
});

/*
 * No client-held idempotency key. Every write has a natural key the database
 * enforces, and two of these responses carry a one-time secret: serving a retry
 * of either would mean this surface had stored one in order to serve it again.
 */
it('offers no idempotency key and records why', function () {
    foreach ((array) glob(dirname(__DIR__, 2).'/src/Http/*.php') as $file) {
        expect(strtolower((string) file_get_contents((string) $file)))->not->toContain('idempotency-key');
    }

    expect(file_get_contents(dirname(__DIR__, 2).'/docs/domain.md'))->toContain('no idempotency key');
});
