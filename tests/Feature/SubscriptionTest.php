<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterEventName;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;

it('registers an event name and lists the catalogue', function () {
    $created = $this->actingAs(actor([Scope::MANAGE]))
        ->postJson(api('event-names'), ['name' => 'order.paid', 'description' => 'An order was paid for.']);

    $created->assertCreated();

    expect($created->json('data.name'))->toBe('order.paid')
        ->and($created->json('data.description'))->toBe('An order was paid for.');

    $bare = $this->actingAs(actor([Scope::MANAGE]))->postJson(api('event-names'), ['name' => 'order.refunded']);

    expect($bare->json('data.description'))->toBeNull();

    $listed = $this->actingAs(actor())->getJson(api('event-names'));

    expect($listed->json('data'))->toHaveCount(2)
        ->and($listed->json('data.0.name'))->toBe('order.paid');
});

it('refuses a duplicate event name', function () {
    (new RegisterEventName())('merchant-a', 'order.paid');

    $response = $this->actingAs(actor([Scope::MANAGE]))->postJson(api('event-names'), ['name' => 'order.paid']);

    $response->assertStatus(409);

    expect($response->json('error.code'))->toBe('event_name_already_registered');
});

it('subscribes an endpoint to a registered name', function () {
    $seed = seeded();
    (new RegisterEventName())('merchant-a', 'order.refunded');

    $response = $this->actingAs(actor([Scope::MANAGE]))
        ->postJson(api("endpoints/{$seed['endpoint_id']}/subscriptions"), ['event_name' => 'order.refunded']);

    $response->assertCreated();

    expect($response->json('data.event_name'))->toBe('order.refunded')
        ->and($response->json('data.endpoint_id'))->toBe($seed['endpoint_id']);
});

/*
 * An unregistered name is a named rejection and never a subscription that
 * matches nothing. The host accepted any string and then silently matched
 * none, so `order.payed` was indistinguishable from an integration that had
 * gone quiet.
 */
it('refuses a subscription to a name nobody registered', function () {
    $seed = seeded();

    $response = $this->actingAs(actor([Scope::MANAGE]))
        ->postJson(api("endpoints/{$seed['endpoint_id']}/subscriptions"), ['event_name' => 'order.payed']);

    $response->assertStatus(422);

    expect($response->json('error.code'))->toBe('event_name_not_registered')
        ->and($response->json('error.resubmittable'))->toBeTrue();
});

it('refuses a subscription it already holds', function () {
    $seed = seeded();

    $response = $this->actingAs(actor([Scope::MANAGE]))
        ->postJson(api("endpoints/{$seed['endpoint_id']}/subscriptions"), ['event_name' => 'order.paid']);

    $response->assertStatus(409);

    expect($response->json('error.code'))->toBe('subscription_already_held');
});

/*
 * Unsubscribing something not held and unsubscribing from somebody else's
 * endpoint are one answer, because the domain's delete reports the same false
 * for both. A silent 204 would make a mistyped name look like success.
 */
it('answers the same 404 for a subscription not held and an endpoint that is not yours', function () {
    $mine = seeded();
    seeded('merchant-b', 'order.paid', 'https://203.0.113.30/hook', 'partner-b');
    $actor = actor([Scope::MANAGE]);

    $this->actingAs($actor)
        ->deleteJson(api("endpoints/{$mine['endpoint_id']}/subscriptions/order.paid"))
        ->assertNoContent();

    $again = $this->actingAs($actor)->deleteJson(api("endpoints/{$mine['endpoint_id']}/subscriptions/order.paid"));
    $theirs = $this->actingAs($actor)->deleteJson(api('endpoints/2/subscriptions/order.paid'));

    $again->assertNotFound();
    $theirs->assertNotFound();

    expect($again->json())->toBe($theirs->json());
});
