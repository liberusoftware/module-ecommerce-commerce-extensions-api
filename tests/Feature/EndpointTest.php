<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterExtension;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;

/*
 * The secret exists outside the module for exactly one response. Nothing reads
 * one back: both columns are encrypted and hidden, and there is no query that
 * returns one. A surface that failed to publish it here would have destroyed
 * it, and the only recovery would be another rotation.
 */
it('returns the signing secret once, when the endpoint is added', function () {
    $extension = (new RegisterExtension())('merchant-a', 'partner-1', 'A Partner');

    $response = $this->actingAs(actor([Scope::MANAGE]))->postJson(api('endpoints'), [
        'extension_id' => $extension->id,
        'url' => 'https://203.0.113.10/hook',
    ]);

    $response->assertCreated();

    expect($response->json('data.secret'))->toStartWith('whsec_')
        ->and($response->json('data.previous_secret_expires_at'))->toBeNull();
});

it('never publishes a secret in any other response', function () {
    $seed = seeded();
    $actor = actor(Scope::all());

    $rotated = $this->actingAs($actor)->postJson(api("endpoints/{$seed['endpoint_id']}/secret-rotations"));

    $rotated->assertOk();

    $secret = (string) $rotated->json('data.secret');

    expect($secret)->toStartWith('whsec_')
        ->and($secret)->not->toBe($seed['secret'])
        ->and($rotated->json('data.previous_secret_expires_at'))->not->toBeNull();

    $elsewhere = [
        $this->actingAs($actor)->getJson(api('endpoints')),
        $this->actingAs($actor)->putJson(api("endpoints/{$seed['endpoint_id']}/retirement")),
        $this->actingAs($actor)->deleteJson(api("endpoints/{$seed['endpoint_id']}/retirement")),
        $this->actingAs($actor)->deleteJson(api("endpoints/{$seed['endpoint_id']}/previous-secret")),
    ];

    foreach ($elsewhere as $response) {
        $body = (string) $response->getContent();

        expect($body)->not->toContain('whsec_');
        expect($body)->not->toContain('secret"');
    }
});

it('refuses a duplicate endpoint rather than re-serving a one-time secret', function () {
    $seed = seeded();

    $response = $this->actingAs(actor([Scope::MANAGE]))->postJson(api('endpoints'), [
        'extension_id' => $seed['extension_id'],
        'url' => 'https://203.0.113.10/hook',
    ]);

    $response->assertStatus(409);

    expect($response->json('error.code'))->toBe('endpoint_already_registered');
});

it('refuses a destination that is not https and one that resolves into private space', function () {
    $extension = (new RegisterExtension())('merchant-a', 'partner-1', 'A Partner');
    $actor = actor([Scope::MANAGE]);

    foreach (['http://203.0.113.10/hook', 'https://127.0.0.1/hook'] as $url) {
        $response = $this->actingAs($actor)->postJson(api('endpoints'), [
            'extension_id' => $extension->id,
            'url' => $url,
        ]);

        $response->assertStatus(422);

        expect($response->json('error.code'))->toBe('endpoint_url_invalid')
            ->and($response->json('error.resubmittable'))->toBeTrue();
    }
});

/*
 * `Destination::refusalFor()` reports `DestinationIsNotHttps` for three
 * different faults — a wrong scheme, a URL `parse_url` rejects, and one with no
 * host at all — so `https:///hook` comes back as "not https" when it is. The
 * exception carries a typed `$reason` this surface could publish, and does not:
 * a reason that is sometimes wrong is worse than one message that is always
 * true. Every refused URL gets the same body.
 */
it('gives one answer for every URL the domain refuses, because the reason is not always right', function () {
    $extension = (new RegisterExtension())('merchant-a', 'partner-1', 'A Partner');
    $actor = actor([Scope::MANAGE]);
    $bodies = [];

    foreach (['http://203.0.113.10/hook', 'https:///hook', 'https://10.0.0.1/hook'] as $url) {
        $response = $this->actingAs($actor)->postJson(api('endpoints'), [
            'extension_id' => $extension->id,
            'url' => $url,
        ]);

        $response->assertStatus(422);

        $bodies[] = $response->json();
    }

    expect($bodies[0])->toBe($bodies[1])
        ->and($bodies[1])->toBe($bodies[2])
        ->and($bodies[0]['error'])->not->toHaveKey('reason');
});

/*
 * Every length this surface accepts is bounded before the domain sees it, so a
 * value too wide for a column is a 422 naming the field rather than a
 * `QueryException` the domain catches and renames. `AddEndpoint`,
 * `RegisterExtension`, `RegisterEventName` and `Subscribe` all catch that
 * exception broadly and report a duplicate, so the 409 is only as trustworthy
 * as the catch: keeping the common case away from it is this package's job.
 */
it('bounds every value it accepts before the domain writes it', function () {
    $seed = seeded();
    $actor = actor([Scope::MANAGE]);

    $overlong = [
        ['extensions', ['extension_ref' => str_repeat('a', 129), 'name' => 'A Partner'], 'extension_ref'],
        ['extensions', ['extension_ref' => 'partner-2', 'name' => str_repeat('a', 192)], 'name'],
        ['endpoints', ['extension_id' => $seed['extension_id'], 'url' => 'https://203.0.113.10/'.str_repeat('a', 1024)], 'url'],
        ['event-names', ['name' => str_repeat('a', 129)], 'name'],
        ['event-names', ['name' => 'order.shipped', 'description' => str_repeat('a', 192)], 'description'],
        ["endpoints/{$seed['endpoint_id']}/subscriptions", ['event_name' => str_repeat('a', 129)], 'event_name'],
    ];

    foreach ($overlong as [$path, $payload, $field]) {
        $response = $this->actingAs($actor)->postJson(api($path), $payload);

        $response->assertStatus(422);

        expect($response->json('error.code'))->toBe('validation_failed')
            ->and($response->json('error.fields'))->toHaveKey($field);
    }
});

it('answers the same 404 for an extension that is not yours and one nobody minted', function () {
    seeded('merchant-b');
    $actor = actor([Scope::MANAGE]);

    $theirs = $this->actingAs($actor)->postJson(api('endpoints'), [
        'extension_id' => 1,
        'url' => 'https://203.0.113.11/hook',
    ]);

    $nobody = $this->actingAs($actor)->postJson(api('endpoints'), [
        'extension_id' => 9999,
        'url' => 'https://203.0.113.11/hook',
    ]);

    $theirs->assertNotFound();
    $nobody->assertNotFound();

    expect($theirs->json())->toBe($nobody->json())
        ->and($theirs->json('error.code'))->toBe('not_found');
});

it('expires a rotated-out secret early, and refuses when there is none', function () {
    $seed = seeded();
    $actor = actor([Scope::MANAGE]);
    $path = api("endpoints/{$seed['endpoint_id']}/previous-secret");

    $none = $this->actingAs($actor)->deleteJson($path);

    $none->assertStatus(409);

    expect($none->json('error.code'))->toBe('no_previous_secret');

    $this->actingAs($actor)->postJson(api("endpoints/{$seed['endpoint_id']}/secret-rotations"))->assertOk();

    $expired = $this->actingAs($actor)->deleteJson($path);

    $expired->assertOk();

    expect($expired->json('data.previous_secret_expires_at'))->toBeNull();
});

it('lists endpoints, filtered to one extension', function () {
    $seed = seeded();
    seeded('merchant-a', 'order.refunded', 'https://203.0.113.20/hook', 'partner-2');

    $all = $this->actingAs(actor())->getJson(api('endpoints'));
    $filtered = $this->actingAs(actor())->getJson(api("endpoints?extension={$seed['extension_id']}"));

    expect($all->json('data'))->toHaveCount(2)
        ->and($filtered->json('data'))->toHaveCount(1)
        ->and($filtered->json('data.0.extension_id'))->toBe($seed['extension_id'])
        ->and($filtered->json('data.0.url'))->toBe('https://203.0.113.10/hook');
});
