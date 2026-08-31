<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Api\Tests\Doubles\FakeTransport;

/** Raise one event and hand back the delivery it owes. */
function owed(array $overrides = []): int
{
    seeded();

    $response = test()->actingAs(actor([Scope::RAISE]))->postJson(api('events'), array_merge([
        'event_name' => 'order.paid',
        'cause_ref' => 'order-77',
        'subject_ref' => 'person-9',
        'payload' => '{"order":"77"}',
    ], $overrides));

    $response->assertCreated();

    return (int) $response->json('data.0.delivery_id');
}

function attemptDelivery(int $delivery): TestResponse
{
    return test()->actingAs(actor([Scope::DELIVER]))->postJson(api("deliveries/{$delivery}/attempts"));
}

it('delivers, settles, and reports what the receiver said', function () {
    $delivery = owed();
    $transport = bindTransport();

    $response = attemptDelivery($delivery);

    $response->assertOk();

    expect($response->json('data.outcome'))->toBe('delivered')
        ->and($response->json('data.sequence'))->toBe(1)
        ->and($response->json('data.response_status'))->toBe(200)
        ->and($response->json('data.refusal_reason'))->toBeNull()
        ->and($response->json('data.delivery_settled'))->toBeTrue()
        ->and($response->json('data.delivery_outcome'))->toBe('delivered')
        ->and($transport->sent)->toHaveCount(1);

    $shown = $this->actingAs(actor())->getJson(api("deliveries/{$delivery}"));

    expect($shown->json('data.attempt_count'))->toBe(1)
        ->and($shown->json('data.attempts.0.response_status'))->toBe(200)
        ->and($shown->json('data.attempts.0.transport_completed'))->toBeTrue()
        ->and($shown->json('data.attempts.0.excerpt'))->toBe('ok')
        ->and($shown->json('data.attempts.0.duration_ms'))->toBe(12)
        ->and($shown->json('data.payload'))->toBe('{"order":"77"}')
        ->and($shown->json('data.subject_ref'))->toBe('person-9');
});

/*
 * An unbound seam removes the one claim it controls and nothing else. It is a
 * row with a reason, at 200 — not a 503 and not zero deliveries — and it takes
 * no slot, so binding a transport later does not find the delivery exhausted.
 */
it('renders an unbound transport as a refusal with its reason, still owing the delivery', function () {
    $delivery = owed();

    $response = attemptDelivery($delivery);

    $response->assertOk();

    expect($response->json('data.outcome'))->toBe('refused')
        ->and($response->json('data.refusal_reason'))->toBe('no_transport_bound')
        ->and($response->json('data.sequence'))->toBeNull()
        ->and($response->json('data.attempt_id'))->not->toBeNull()
        ->and($response->json('data.delivery_settled'))->toBeFalse()
        ->and($response->json('data.delivery_outcome'))->toBeNull();

    bindTransport();

    expect(attemptDelivery($delivery)->json('data.sequence'))->toBe(1);
});

it('refuses to send to a retired endpoint and to a retired extension', function () {
    $delivery = owed();
    bindTransport();

    $this->actingAs(actor([Scope::MANAGE]))->putJson(api('endpoints/1/retirement'))->assertOk();

    expect(attemptDelivery($delivery)->json('data.refusal_reason'))->toBe('endpoint_retired');

    $this->actingAs(actor([Scope::MANAGE]))->deleteJson(api('endpoints/1/retirement'))->assertOk();
    $this->actingAs(actor([Scope::MANAGE]))->putJson(api('extensions/1/retirement'))->assertOk();

    expect(attemptDelivery($delivery)->json('data.refusal_reason'))->toBe('extension_retired');
});

/*
 * The window is the single bound; there is no attempt cap. A delivery attempted
 * past its own expiry is abandoned, which is a settled outcome and not a
 * failure of the request that reported it.
 */
it('abandons a delivery attempted after its window has closed', function () {
    $delivery = owed();
    bindTransport();

    Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00'));

    $response = attemptDelivery($delivery);

    $response->assertOk();

    expect($response->json('data.refusal_reason'))->toBe('window_closed')
        ->and($response->json('data.delivery_settled'))->toBeTrue()
        ->and($response->json('data.delivery_outcome'))->toBe('abandoned');
});

/*
 * A transport that never completed leaves an excerpt with no status, and the
 * excerpt is the exception message rather than a response body. The field is
 * not called `response` for that reason.
 */
it('labels an excerpt by whether the transport completed', function () {
    $delivery = owed();
    bindTransport(new FakeTransport(throws: true));

    $response = attemptDelivery($delivery);

    expect($response->json('data.outcome'))->toBe('failed')
        ->and($response->json('data.response_status'))->toBeNull()
        ->and($response->json('data.delivery_settled'))->toBeFalse();

    $shown = $this->actingAs(actor())->getJson(api("deliveries/{$delivery}"));

    expect($shown->json('data.attempts.0.transport_completed'))->toBeFalse()
        ->and($shown->json('data.attempts.0.excerpt'))->toBe('connection refused')
        ->and($shown->json('data.attempts.0.response_status'))->toBeNull()
        // The module stores 0 for a request that threw. A zero substituted for
        // an unknown is a measurement nobody took, so nothing is published.
        ->and($shown->json('data.attempts.0.duration_ms'))->toBeNull();
});

it('refuses to reopen a settled delivery', function () {
    $delivery = owed();
    bindTransport();

    attemptDelivery($delivery)->assertOk();

    $again = attemptDelivery($delivery);

    $again->assertStatus(409);

    expect($again->json('error.code'))->toBe('delivery_already_settled')
        ->and($again->json('error.resubmittable'))->toBeFalse();
});

it('publishes the due list a worker asks for, and drops a settled delivery from it', function () {
    $delivery = owed();
    bindTransport();

    $due = $this->actingAs(actor([Scope::DELIVER]))->getJson(api('deliveries/due?limit=10'));

    $due->assertOk();

    expect($due->json('data'))->toHaveCount(1)
        ->and($due->json('data.0.id'))->toBe($delivery)
        ->and($due->json('data.0.attempt_count'))->toBe(0)
        ->and($due->json('data.0.settled'))->toBeFalse()
        ->and($due->json('data.0'))->not->toHaveKey('payload');

    attemptDelivery($delivery)->assertOk();

    expect($this->actingAs(actor([Scope::DELIVER]))->getJson(api('deliveries/due'))->json('data'))->toBe([]);
});

it('filters the log by endpoint and by outcome', function () {
    $delivery = owed();
    bindTransport();
    attemptDelivery($delivery)->assertOk();

    $actor = actor();

    expect($this->actingAs($actor)->getJson(api('deliveries?outcome=delivered'))->json('data'))->toHaveCount(1)
        ->and($this->actingAs($actor)->getJson(api('deliveries?outcome=abandoned'))->json('data'))->toBe([])
        ->and($this->actingAs($actor)->getJson(api('deliveries?endpoint=1'))->json('data'))->toHaveCount(1)
        ->and($this->actingAs($actor)->getJson(api('deliveries?endpoint=2'))->json('data'))->toBe([]);

    $this->actingAs($actor)->getJson(api('deliveries?outcome=lost'))->assertStatus(422);
});

it('answers the same 404 for a delivery that is not yours and one nobody raised', function () {
    seeded('merchant-b');

    $theirs = $this->actingAs(actor())->getJson(api('deliveries/1'));
    $nobody = $this->actingAs(actor())->getJson(api('deliveries/9999'));

    $theirs->assertNotFound();
    $nobody->assertNotFound();

    expect($theirs->json())->toBe($nobody->json());
});
