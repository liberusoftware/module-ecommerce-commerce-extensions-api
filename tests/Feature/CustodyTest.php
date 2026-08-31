<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Api\Tests\Doubles\FakeTransport;

/** Raise one event for a merchant and leave `$attempts` attempt rows against it. */
function withAttempts(string $tenant, string $causeRef, int $attempts): int
{
    $raised = test()->actingAs(actor([Scope::RAISE], $tenant))->postJson(api('events'), [
        'event_name' => 'order.paid',
        'cause_ref' => $causeRef,
        'subject_ref' => 'person-9',
        'payload' => '{}',
    ]);

    $raised->assertCreated();

    $delivery = (int) $raised->json('data.0.delivery_id');

    bindTransport(new FakeTransport(status: 500, body: 'nope'));

    for ($i = 0; $i < $attempts; $i++) {
        test()->actingAs(actor([Scope::DELIVER], $tenant))
            ->postJson(api("deliveries/{$delivery}/attempts"))
            ->assertOk();
    }

    return $delivery;
}

/*
 * The guarded restatement, in both directions and on the same data.
 *
 * `withCount()` builds the relation from a fresh instance whose `tenant_id` is
 * null, so an unguarded restatement becomes `where('tenant_id', '')` and every
 * count reports zero — which reads exactly like isolation working. Asserting a
 * *right, non-zero* number is the only assertion that tells them apart.
 */
it('counts a tenant-restated relation correctly from a listing and from a hydrated row', function () {
    seeded();
    seeded('merchant-b', 'order.paid', 'https://203.0.113.30/hook', 'partner-b');

    $mine = withAttempts('merchant-a', 'order-77', 3);
    withAttempts('merchant-b', 'order-99', 1);

    $listed = $this->actingAs(actor())->getJson(api('deliveries'));

    $listed->assertOk();

    expect($listed->json('data'))->toHaveCount(1)
        ->and($listed->json('data.0.id'))->toBe($mine)
        ->and($listed->json('data.0.attempt_count'))->toBe(3);

    $shown = $this->actingAs(actor())->getJson(api("deliveries/{$mine}"));

    expect($shown->json('data.attempt_count'))->toBe(3)
        ->and($shown->json('data.attempts'))->toHaveCount(3)
        ->and(array_column((array) $shown->json('data.attempts'), 'sequence'))->toBe([1, 2, 3]);
});

it('lists only the credential holder rows in every listing', function () {
    seeded();
    seeded('merchant-b', 'order.paid', 'https://203.0.113.30/hook', 'partner-b');

    $mine = actor();
    $theirs = actor([Scope::READ], 'merchant-b');

    foreach (['extensions', 'endpoints', 'event-names'] as $listing) {
        expect($this->actingAs($mine)->getJson(api($listing))->json('data'))->toHaveCount(1)
            ->and($this->actingAs($theirs)->getJson(api($listing))->json('data'))->toHaveCount(1);
    }

    expect($this->actingAs($mine)->getJson(api('endpoints'))->json('data.0.url'))->toBe('https://203.0.113.10/hook')
        ->and($this->actingAs($theirs)->getJson(api('endpoints'))->json('data.0.url'))->toBe('https://203.0.113.30/hook');
});

/*
 * Another merchant's reference and one nobody ever minted are one answer with
 * one status and one body, on every write. Nothing here checks custody before
 * the lookup: a 403 in front of the domain's `firstOrFail()` would publish
 * which rows exist.
 */
it('answers every reference it may not have with the same 404', function () {
    seeded('merchant-b');
    $actor = actor(Scope::all());

    $writes = [
        ['put', 'extensions/1/retirement'],
        ['delete', 'extensions/1/retirement'],
        ['put', 'endpoints/1/retirement'],
        ['delete', 'endpoints/1/retirement'],
        ['post', 'endpoints/1/secret-rotations'],
        ['delete', 'endpoints/1/previous-secret'],
        ['post', 'endpoints/1/subscriptions'],
        ['delete', 'endpoints/1/subscriptions/order.paid'],
        ['post', 'deliveries/1/attempts'],
        ['get', 'deliveries/1'],
    ];

    foreach ($writes as [$method, $path]) {
        $theirs = $this->actingAs($actor)->json($method, api($path), ['event_name' => 'order.paid']);
        $nobody = $this->actingAs($actor)->json($method, api(str_replace('/1', '/9999', $path)), ['event_name' => 'order.paid']);

        $theirs->assertNotFound();

        expect($theirs->json())->toBe($nobody->json(), $path);
    }
});
