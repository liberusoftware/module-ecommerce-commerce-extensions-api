<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Api\Http\Failure;

/**
 * Every exception this surface owns, written out by hand rather than found by
 * scanning a directory: the point is to fail when the domain adds one.
 *
 * `ModelNotFoundException` is here because it is how the domain answers both
 * "not yours" and "no such row" — every action and query is
 * `where('tenant_id', …)` then `firstOrFail()`. It lives in `illuminate/database`,
 * which is why this package requires that component although nothing in its own
 * code names it otherwise.
 *
 * @return list<class-string>
 */
function ownedExceptions(): array
{
    $domain = 'Liberu\\Ecommerce\\CommerceExtensions\\Exceptions\\';

    return array_merge(
        ['Illuminate\\Database\\Eloquent\\ModelNotFoundException'],
        array_map(static fn (string $name): string => $domain.$name, [
            'AttemptsAreAppendOnly',
            'CauseReferenceIsClaimed',
            'DeliveryIsAlreadySettled',
            'EndpointIsAlreadyRegistered',
            'EndpointUrlIsInvalid',
            'EventNameIsAlreadyRegistered',
            'EventNameIsNotRegistered',
            'ExtensionIsAlreadyRegistered',
            'SubscriptionIsAlreadyHeld',
            'ThereIsNoPreviousSecret',
        ]),
    );
}

/*
 * A mis-typed key would silently never match a thrown exception, so every class
 * named is checked to autoload before anything leans on it.
 */
it('names only exception classes that actually exist', function () {
    $base = 'Liberu\\Ecommerce\\CommerceExtensions\\Exceptions\\CommerceExtensionsException';

    foreach ([...ownedExceptions(), $base] as $class) {
        expect(class_exists($class))->toBeTrue("[{$class}] does not autoload.");
    }
});

it('maps every exception the domain publishes, and exactly eleven in all', function () {
    $unmapped = array_values(array_diff(ownedExceptions(), array_keys(Failure::map())));
    $unknown = array_values(array_diff(array_keys(Failure::map()), ownedExceptions()));

    expect($unmapped)->toBe([])
        ->and($unknown)->toBe([])
        ->and(ownedExceptions())->toHaveCount(11);
});

it('never maps the abstract base, which would swallow every future exception', function () {
    expect(array_keys(Failure::map()))
        ->not->toContain('Liberu\\Ecommerce\\CommerceExtensions\\Exceptions\\CommerceExtensionsException');
});

it('classifies every mapping', function () {
    foreach (Failure::map() as $class => $mapping) {
        expect($mapping)->toHaveKeys(['status', 'code', 'message', 'resubmittable'], $class)
            ->and($mapping['resubmittable'])->toBeBool()
            ->and($mapping['status'])->toBeGreaterThanOrEqual(400)
            ->and($mapping['status'])->toBeLessThan(500);
    }
});

/*
 * Resubmittable means the input was wrong and correcting it is the whole
 * remedy. There is no 503 here, and that is a fact about the domain rather than
 * an omission: its one seam refuses inside an `AttemptReport`, as a row with a
 * reason, so an unbound transport is a 200 carrying that reason.
 */
it('makes resubmittable mean 422 and nothing else', function () {
    foreach (Failure::map() as $class => $mapping) {
        expect($mapping['resubmittable'])->toBe($mapping['status'] === 422, $class);
    }

    expect(array_column(Failure::map(), 'status'))->not->toContain(503);
});

/*
 * Nothing in this domain is transient. There is no rate limit of its own and no
 * in-flight claim to wait out, so no 429, no 423, and no message inviting a
 * wait. A courtesy retry prompt on a permanent refusal is a lie the surface
 * tells on the domain's behalf.
 */
it('never invites a wait', function () {
    foreach (Failure::map() as $class => $mapping) {
        expect($mapping['status'])->not->toBe(429, $class)
            ->and($mapping['status'])->not->toBe(423, $class);

        expect(strtolower($mapping['message']))->not->toContain('try again')
            ->not->toContain('shortly')
            ->not->toContain('in a moment');
    }

    expect(file_get_contents(dirname(__DIR__, 2).'/src/Http/Controllers/Controller.php'))
        ->not->toContain('Retry'.'-After');
});

it('answers every unknown reference identically', function () {
    $notFound = array_values(array_filter(Failure::map(), static fn (array $m): bool => $m['status'] === 404));

    expect($notFound)->toHaveCount(1)
        ->and($notFound[0]['code'])->toBe('not_found')
        ->and($notFound[0]['resubmittable'])->toBeFalse();

    foreach (['extension', 'endpoint', 'delivery', 'subscription', 'event name'] as $tell) {
        expect(strtolower($notFound[0]['message']))->not->toContain($tell);
    }
});

/*
 * No 403 in the table. A 403 for a reference belonging to somebody else
 * confirms it exists, which is the disclosure the 404 avoids. The three this
 * surface emits are about the credential itself.
 */
it('maps no exception to a 403 that could confirm a record exists', function () {
    foreach (Failure::map() as $class => $mapping) {
        expect($mapping['status'])->not->toBe(403, $class);
    }
});

it('renders no domain exception message to a caller', function () {
    foreach ((array) glob(dirname(__DIR__, 2).'/src/Http/Controllers/*.php') as $file) {
        expect(php_strip_whitespace((string) $file))->not->toContain('getMessage()');
    }

    foreach (Failure::map() as $mapping) {
        expect($mapping['message'])->toBeString()->not->toBe('');
    }
});

it('lets an unmapped throwable bubble rather than dressing it as a 4xx', function () {
    expect(Failure::for(new LogicException('something nobody planned for')))->toBeNull();
});

it('shapes every error body the same way', function () {
    expect(Failure::body('a_code', 'A message.', true))
        ->toBe(['error' => ['code' => 'a_code', 'message' => 'A message.', 'resubmittable' => true]]);
});
