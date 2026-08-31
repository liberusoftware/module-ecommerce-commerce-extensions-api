<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\Controller;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Api\OpenApi;
use Liberu\Ecommerce\CommerceExtensions\Enums\AttemptOutcome;
use Liberu\Ecommerce\CommerceExtensions\Enums\DeliveryOutcome;
use Liberu\Ecommerce\CommerceExtensions\Enums\RefusalReason;

/**
 * Every route this package registers, as `METHOD /path` relative to the mount.
 *
 * `HEAD` is dropped: Laravel synthesises one for every `GET` and OpenAPI cannot
 * describe it, so requiring an operation for it would fail parity over a
 * routing detail rather than over anything anybody wrote.
 *
 * @return array<string, RoutingRoute>
 */
function routed(): array
{
    $prefix = 'api/commerce-extensions';
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), $prefix)) {
            continue;
        }

        $path = '/'.ltrim(substr($route->uri(), strlen($prefix)), '/');

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $routes[strtolower($method).' '.rtrim($path, '/')] = $route;
        }
    }

    return $routes;
}

/** @return array<string, array<string, mixed>> */
function documented(): array
{
    $operations = [];

    foreach (OpenApi::document()['paths'] as $path => $item) {
        foreach ($item as $method => $operation) {
            if ($method === 'parameters') {
                continue;
            }

            $operations[$method.' '.$path] = $operation;
        }
    }

    return $operations;
}

it('ships a valid OpenAPI 3.1 document versioned with the manifest', function () {
    $document = OpenApi::document();
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true);

    expect($document['openapi'])->toBe('3.1.0')
        ->and($document['info']['version'])->toBe($manifest['version'])
        ->and($document['paths'])->not->toBeEmpty()
        ->and(OpenApi::path())->toBeFile();
});

it('documents every route it registers', function () {
    expect(array_values(array_diff(array_keys(routed()), array_keys(documented()))))->toBe([]);
});

it('registers a route for every operation it documents', function () {
    expect(array_values(array_diff(array_keys(documented()), array_keys(routed()))))->toBe([]);
});

it('documents the same ability the controller enforces', function () {
    $mismatched = [];
    $routes = routed();

    foreach (documented() as $key => $operation) {
        $controller = $routes[$key]->getController();

        expect($controller)->toBeInstanceOf(Controller::class);

        $enforced = $controller->scopes()[$routes[$key]->getActionMethod()] ?? null;
        $declared = $operation['security'][0]['bearer'][0] ?? null;

        if ($enforced !== $declared) {
            $mismatched[$key] = ['documented' => $declared, 'enforced' => $enforced];
        }
    }

    expect($mismatched)->toBe([]);
});

it('documents only abilities this package publishes', function () {
    foreach (documented() as $operation) {
        expect(Scope::all())->toContain($operation['security'][0]['bearer'][0]);
    }
});

it('gives every operation an identifier, a summary, a description and a tag', function () {
    $ids = [];
    $tags = array_column(OpenApi::document()['tags'], 'name');

    foreach (documented() as $key => $operation) {
        expect($operation)->toHaveKeys(['operationId', 'summary', 'description', 'tags', 'responses'], $key)
            ->and($tags)->toContain($operation['tags'][0]);

        $ids[] = $operation['operationId'];
    }

    expect(array_unique($ids))->toHaveCount(count($ids));
});

it('documents 401 and 403 on every operation, because every one is authenticated and scoped', function () {
    foreach (documented() as $key => $operation) {
        $statuses = array_map(strval(...), array_keys($operation['responses']));

        // `toContain` is variadic, so a second argument is another needle and
        // not a message. One argument, always.
        expect($statuses)->toContain('401');
        expect($statuses)->toContain('403');
    }
});

it('classifies every documented failure', function () {
    $error = OpenApi::document()['components']['schemas']['Error'];

    expect($error['required'])->toContain('error')
        ->and($error['properties']['error']['required'])->toContain('resubmittable');
});

/*
 * Nothing here is transient: no rate limit of the module's own, no in-flight
 * claim to wait out. So no response carries a wait and nothing in the document
 * invites one.
 */
it('promises no wait anywhere', function () {
    $prose = strtolower((string) json_encode(OpenApi::document()));

    expect($prose)->not->toContain('try again')
        ->not->toContain('shortly')
        ->not->toContain('retry-after');

    foreach (OpenApi::document()['components']['responses'] as $response) {
        expect($response)->not->toHaveKey('headers');
    }

    foreach (documented() as $operation) {
        $statuses = array_map(strval(...), array_keys($operation['responses']));

        expect($statuses)->not->toContain('429');
        expect($statuses)->not->toContain('423');
    }
});

it('accepts no merchant identifier anywhere in the document', function () {
    $document = (string) json_encode(OpenApi::document());

    expect($document)->not->toContain('tenant_id')
        ->not->toContain('team_id')
        ->not->toContain('store_id');
});

/*
 * The signing secret exists outside the module for exactly one response. Two
 * operations publish it and no schema but `IssuedSecret` names it.
 */
it('publishes a secret in one schema and two operations', function () {
    $schemas = OpenApi::document()['components']['schemas'];

    foreach ($schemas as $name => $schema) {
        if ($name === 'IssuedSecret') {
            continue;
        }

        expect((string) json_encode($schema))->not->toContain('"secret"');
    }

    $issuing = [];

    foreach (documented() as $key => $operation) {
        if (str_contains((string) json_encode($operation), 'IssuedSecret')) {
            $issuing[] = $key;
        }
    }

    sort($issuing);

    expect($issuing)->toBe([
        'post /endpoints',
        'post /endpoints/{endpoint}/secret-rotations',
    ]);
});

/*
 * No idempotency key. Every write has a natural key the database enforces, and
 * two of these responses carry a one-time secret: serving a retry of either
 * would mean this surface had stored one in order to serve it again.
 */
it('offers no idempotency key and says why on the operation that could have wanted one', function () {
    $document = strtolower((string) json_encode(OpenApi::document()));

    expect($document)->not->toContain('idempotency-key')
        ->and($document)->not->toContain('idempotency_key');

    expect(documented()['post /events']['description'])
        ->toContain('natural key')
        ->toContain('no idempotency key');
});

/*
 * The two nulls this surface refuses to let a caller read as an absence. A
 * delivery still owed has no outcome *yet*, and that is not the same as having
 * none.
 */
it('publishes an unsettled delivery outcome as null and says what the null means', function () {
    $schemas = OpenApi::document()['components']['schemas'];

    expect($schemas['AttemptReport']['properties']['delivery_outcome']['description'])
        ->toContain('still owed')
        ->toContain('**not**');

    expect($schemas['Delivery']['properties']['outcome']['description'])->toContain('**not**');

    expect($schemas['AttemptReport']['properties']['attempt_id']['description'])
        ->toContain('concurrent_attempt');

    expect($schemas['Attempt']['properties']['transport_completed']['description'])
        ->toContain('**not** a response body');
});

/*
 * Every refusal the domain can record is a value a caller will see, so the
 * document enumerates the enum rather than a remembered subset of it. A new
 * case in the domain fails here rather than arriving undocumented.
 */
it('documents every enumeration the domain publishes, in full', function () {
    $schemas = OpenApi::document()['components']['schemas'];

    $reasons = array_column(RefusalReason::cases(), 'value');
    $attempts = array_column(AttemptOutcome::cases(), 'value');
    $outcomes = array_column(DeliveryOutcome::cases(), 'value');

    expect($reasons)->toHaveCount(7);

    foreach ([
        $schemas['Attempt']['properties']['refusal_reason']['enum'],
        $schemas['AttemptReport']['properties']['refusal_reason']['enum'],
    ] as $documented) {
        expect(array_values(array_diff($reasons, $documented)))->toBe([])
            ->and(array_values(array_diff(array_filter($documented), $reasons)))->toBe([]);
    }

    foreach ([
        $schemas['Attempt']['properties']['outcome']['enum'],
        $schemas['AttemptReport']['properties']['outcome']['enum'],
    ] as $documented) {
        expect($documented)->toBe($attempts);
    }

    foreach ([
        $schemas['Delivery']['properties']['outcome']['enum'],
        $schemas['AttemptReport']['properties']['delivery_outcome']['enum'],
    ] as $documented) {
        expect(array_values(array_diff($outcomes, $documented)))->toBe([]);
    }
});

/*
 * The listing shape carries neither the stored payload nor the subject. Both
 * are opaque bytes a caller chose, and a listing is the shape that gets logged,
 * cached and pasted into a ticket.
 */
it('keeps the payload and the subject off the listing shape', function () {
    $schemas = OpenApi::document()['components']['schemas'];

    expect(array_keys($schemas['Delivery']['properties']))
        ->not->toContain('payload')
        ->not->toContain('subject_ref');

    expect($schemas['DeliveryDetail']['allOf'][1]['required'])
        ->toContain('payload')
        ->toContain('subject_ref');
});

/*
 * Absences that are decisions, published as such rather than left for a reader
 * to notice.
 */
it('records the endpoints it does not publish and whose job they are', function () {
    foreach (array_keys(documented()) as $key) {
        expect($key)->not->toContain('erasure')
            ->not->toContain('subject-record')
            ->not->toContain('prune')
            ->not->toContain('manifest')
            ->not->toContain('install');
    }

    expect(OpenApi::document()['info']['description'])
        ->toContain('no privacy endpoint')
        ->toContain('no retention endpoint')
        ->toContain('module-manager');
});

it('names every path parameter its route declares', function () {
    $paths = OpenApi::document()['paths'];

    foreach (documented() as $key => $operation) {
        [, $path] = explode(' ', $key, 2);

        preg_match_all('/\{([A-Za-z]+)\}/', $path, $matches);

        $declared = array_map(
            static fn (array $parameter): string => basename((string) ($parameter['$ref'] ?? $parameter['name'] ?? '')),
            array_merge($paths[$path]['parameters'] ?? [], $operation['parameters'] ?? []),
        );

        foreach ($matches[1] as $name) {
            // One needle only: `toContain` is variadic, so a second argument
            // would be another needle rather than a failure message.
            expect(implode(' ', $declared))->toContain(ucfirst($name));
        }
    }
});
