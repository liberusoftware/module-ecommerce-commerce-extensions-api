<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\Controller;

/*
 * Six of the domain's entry points take an instant and the domain reads no
 * clock of its own, so a request that resolved `now()` twice could raise a
 * delivery whose window began before the attempt due at it. One read, in one
 * place, shared.
 */
it('reads the clock once per request', function () {
    $controller = new class() extends Controller {};

    $now = new ReflectionMethod($controller, 'now');

    expect($now->invoke($controller))->toBe($now->invoke($controller));
});

it('names exactly one file in src that may read the clock', function () {
    $readers = [];

    foreach ((array) glob(dirname(__DIR__, 2).'/src/*/*/*.php') as $file) {
        $readers[] = $file;
    }

    foreach ([dirname(__DIR__, 2).'/src', dirname(__DIR__, 2).'/src/Http'] as $directory) {
        foreach ((array) glob($directory.'/*.php') as $file) {
            $readers[] = $file;
        }
    }

    $found = [];

    foreach ($readers as $file) {
        if (str_contains((string) file_get_contents((string) $file), 'Carbon::now()')) {
            $found[] = basename((string) $file);
        }
    }

    expect($found)->toBe(['Controller.php']);
});
