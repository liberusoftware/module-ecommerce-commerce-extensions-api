<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;

function raise(array $overrides = [], ?string $tenant = 'merchant-a'): TestResponse
{
    return test()->actingAs(actor([Scope::RAISE], $tenant))->postJson(api('events'), array_merge([
        'event_name' => 'order.paid',
        'cause_ref' => 'order-77',
        'subject_ref' => 'person-9',
        'payload' => '{"order":"77"}',
    ], $overrides));
}

it('raises one delivery per subscribed endpoint', function () {
    seeded();

    $response = raise();

    $response->assertCreated();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.already_raised'))->toBeFalse()
        ->and($response->json('data.0.delivery_ref'))->toBeString();
});

/*
 * The cause reference is the natural key and it is the caller's own. Raising
 * the same cause for the same subject is a replay of what already exists, so
 * nothing is created and the status is 200.
 */
it('answers 200 and already_raised for a replay of the same cause and subject', function () {
    seeded();

    raise()->assertCreated();

    $replay = raise();

    $replay->assertOk();

    expect($replay->json('data.0.already_raised'))->toBeTrue();
});

/*
 * The same cause for a *different* subject is a rejection, never the first
 * subject's row handed to this caller. That is wave 16's sharpest defect, one
 * layer up.
 */
it('refuses a cause reference another subject already holds', function () {
    seeded();

    raise()->assertCreated();

    $stolen = raise(['subject_ref' => 'person-10']);

    $stolen->assertStatus(409);

    expect($stolen->json('error.code'))->toBe('cause_reference_claimed')
        ->and($stolen->json('error.resubmittable'))->toBeFalse();
});

/** An event nobody subscribes to is an empty list and a success, never an error. */
it('answers 200 with no deliveries when nothing subscribes', function () {
    seeded();

    $this->actingAs(actor([Scope::MANAGE]))->postJson(api('event-names'), ['name' => 'order.refunded'])->assertCreated();

    $response = raise(['event_name' => 'order.refunded']);

    $response->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('refuses an event name nobody registered', function () {
    seeded();

    $response = raise(['event_name' => 'order.payed']);

    $response->assertStatus(422);

    expect($response->json('error.code'))->toBe('event_name_not_registered');
});

it('raises a subjectless event', function () {
    seeded();

    $response = raise(['subject_ref' => null]);

    $response->assertCreated();

    $delivery = $this->actingAs(actor())->getJson(api('deliveries/'.$response->json('data.0.delivery_id')));

    expect($delivery->json('data.subject_ref'))->toBeNull();
});

/*
 * The module stores the payload in a text column and bounds it nowhere, so an
 * over-long one would be a truncated body or a failed write. Bytes, not
 * characters: a multibyte payload inside the character limit can be well past
 * the byte one.
 */
it('refuses a payload larger than this deployment stores', function () {
    seeded();
    Config::set('commerce-extensions-api.payload.max_bytes', 16);

    $inside = raise(['payload' => '{"a":"bbbbbbb"}']);
    $over = raise(['cause_ref' => 'order-78', 'payload' => str_repeat('é', 12)]);

    $inside->assertCreated();
    $over->assertStatus(422);

    expect($over->json('error.fields'))->toHaveKey('payload');
});

it('ignores a merchant a caller tries to name in the body', function () {
    seeded();
    seeded('merchant-b', 'order.paid', 'https://203.0.113.30/hook', 'partner-b');

    raise(['tenant_id' => 'merchant-b', 'team_id' => 'merchant-b'])->assertCreated();

    $mine = $this->actingAs(actor())->getJson(api('deliveries'));
    $theirs = $this->actingAs(actor([Scope::READ], 'merchant-b'))->getJson(api('deliveries'));

    expect($mine->json('data'))->toHaveCount(1)
        ->and($theirs->json('data'))->toBe([]);
});

/*
 * The test the host cannot write. An endpoint belonging to one merchant is
 * unreachable from an event raised for another, and the fan-out is a scoped
 * query rather than a read of every endpoint on the deployment.
 */
it('raises nothing to another merchant endpoint subscribed to the same name', function () {
    seeded();
    seeded('merchant-b', 'order.paid', 'https://203.0.113.30/hook', 'partner-b');

    $transport = bindTransport();

    raise()->assertCreated();

    $due = $this->actingAs(actor([Scope::DELIVER]))->getJson(api('deliveries/due'));

    expect($due->json('data'))->toHaveCount(1);

    $this->actingAs(actor([Scope::DELIVER]))
        ->postJson(api('deliveries/'.$due->json('data.0.id').'/attempts'))
        ->assertOk();

    expect($transport->sent)->toHaveCount(1)
        ->and($transport->sent[0]->url)->toBe('https://203.0.113.10/hook');

    $theirs = $this->actingAs(actor([Scope::DELIVER], 'merchant-b'))->getJson(api('deliveries/due'));

    expect($theirs->json('data'))->toBe([]);
});
