<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\ExtensionController;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;

it('refuses an unauthenticated caller', function () {
    $response = $this->getJson(api('extensions'));

    $response->assertStatus(401);

    expect($response->json('error.code'))->toBe('unauthenticated')
        ->and($response->json('error.resubmittable'))->toBeFalse();
});

it('refuses a credential that does not carry the ability', function () {
    $response = $this->actingAs(actor([Scope::READ]))
        ->postJson(api('extensions'), ['extension_ref' => 'partner-1', 'name' => 'A Partner']);

    $response->assertStatus(403);

    expect($response->json('error.code'))->toBe('insufficient_scope');
});

/*
 * Reading, configuring, raising and delivering are four abilities and not one.
 * A service that raises `order.paid` must not be able to point it somewhere of
 * its own, and a worker that drains the queue has no business registering an
 * endpoint.
 */
it('keeps the four abilities apart on the endpoints that matter', function () {
    seeded();

    $refusals = [
        [[Scope::MANAGE], 'get', 'extensions'],
        [[Scope::READ], 'post', 'events'],
        [[Scope::RAISE], 'get', 'deliveries/due'],
        [[Scope::DELIVER], 'post', 'endpoints/1/secret-rotations'],
    ];

    foreach ($refusals as [$abilities, $method, $path]) {
        $this->actingAs(actor($abilities))->json($method, api($path))->assertStatus(403);
    }
});

/*
 * `method_exists()`, never `is_callable()`: Eloquent implements `__call`, so
 * `is_callable([$user, 'tokenCan'])` is true for every model there is and the
 * check would pass for an actor that cannot answer it.
 */
it('refuses a credential that cannot answer an ability question', function () {
    $response = $this->actingAs(new GenericUser(['id' => 1, 'team_id' => 'merchant-a']))
        ->getJson(api('extensions'));

    $response->assertStatus(403);

    expect($response->json('error.code'))->toBe('insufficient_scope');
});

it('refuses a credential attached to no merchant', function () {
    $response = $this->actingAs(actor([Scope::READ], null))->getJson(api('extensions'));

    $response->assertStatus(403);

    expect($response->json('error.code'))->toBe('actor_has_no_tenant');
});

it('reads the merchant off the attribute a host configures', function () {
    seeded('merchant-a');
    Config::set('commerce-extensions-api.actor.tenant_attribute', 'nothing_here');

    $this->actingAs(actor())->getJson(api('extensions'))->assertStatus(403);
});

/*
 * A method absent from a controller's scope map is refused rather than allowed.
 * An unanswered authorization question is not a yes, and no route reaches this,
 * which is the point: the next action added without an entry is refused.
 */
it('refuses an action that publishes no ability', function () {
    $response = (new ExtensionController())->callAction('somethingNobodyPublished', []);

    expect($response->getStatusCode())->toBe(403)
        ->and($response->getData(true))->toBe([
            'error' => [
                'code' => 'insufficient_scope',
                'message' => 'This operation publishes no ability and cannot be called.',
                'resubmittable' => false,
            ],
        ]);
});

/*
 * An unmapped throwable bubbles. A catch-all arm would turn a genuine defect —
 * here, a host that has named a transport class that does not exist — into a
 * plausible-looking 4xx and hide it from whoever is on call.
 */
it('lets an unmapped throwable bubble rather than dressing it as a refusal', function () {
    seeded();

    $raised = $this->actingAs(actor([Scope::RAISE]))->postJson(api('events'), [
        'event_name' => 'order.paid',
        'cause_ref' => 'order-77',
        'subject_ref' => null,
        'payload' => '{}',
    ]);

    Config::set('commerce_extensions.transport', 'A\\Transport\\Nobody\\Wrote');

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs(actor([Scope::DELIVER]))
        ->postJson(api('deliveries/'.$raised->json('data.0.delivery_id').'/attempts')))
        ->toThrow(Exception::class);
});
