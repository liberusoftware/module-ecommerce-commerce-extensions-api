<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;

it('registers an extension and lists it back', function () {
    $created = $this->actingAs(actor([Scope::MANAGE]))
        ->postJson(api('extensions'), ['extension_ref' => 'partner-1', 'name' => 'A Partner']);

    $created->assertCreated();

    expect($created->json('data.extension_ref'))->toBe('partner-1')
        ->and($created->json('data.live'))->toBeTrue()
        ->and($created->json('data.retired_at'))->toBeNull();

    $listed = $this->actingAs(actor())->getJson(api('extensions'));

    $listed->assertOk();

    expect($listed->json('data.0.name'))->toBe('A Partner')
        ->and($listed->json('meta.has_more'))->toBeFalse();
});

it('refuses a second registration of the same reference', function () {
    seeded();

    $response = $this->actingAs(actor([Scope::MANAGE]))
        ->postJson(api('extensions'), ['extension_ref' => 'partner-1', 'name' => 'Another Name']);

    $response->assertStatus(409);

    expect($response->json('error.code'))->toBe('extension_already_registered')
        ->and($response->json('error.resubmittable'))->toBeFalse();
});

it('rejects a registration missing its reference', function () {
    $response = $this->actingAs(actor([Scope::MANAGE]))->postJson(api('extensions'), ['name' => 'A Partner']);

    $response->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')
        ->and($response->json('error.resubmittable'))->toBeTrue()
        ->and($response->json('error.fields'))->toHaveKey('extension_ref');
});

/*
 * Retiring is a dated fact on a sub-resource, and reinstating deletes that
 * fact. There is no `DELETE extensions/{id}`: deleting one would take the
 * record of what it received with it.
 */
it('retires an extension and reinstates it without losing anything', function () {
    $seed = seeded();
    $actor = actor([Scope::MANAGE, Scope::READ]);

    $retired = $this->actingAs($actor)->putJson(api("extensions/{$seed['extension_id']}/retirement"));

    $retired->assertOk();

    expect($retired->json('data.live'))->toBeFalse()
        ->and($retired->json('data.retired_at'))->not->toBeNull();

    $liveOnly = $this->actingAs($actor)->getJson(api('extensions?live=1'));

    expect($liveOnly->json('data'))->toBe([]);

    $reinstated = $this->actingAs($actor)->deleteJson(api("extensions/{$seed['extension_id']}/retirement"));

    $reinstated->assertOk();

    expect($reinstated->json('data.live'))->toBeTrue()
        ->and($reinstated->json('data.retired_at'))->toBeNull();
});

it('retires idempotently, keeping the instant of the first retirement', function () {
    $seed = seeded();
    $actor = actor([Scope::MANAGE]);
    $path = api("extensions/{$seed['extension_id']}/retirement");

    $first = $this->actingAs($actor)->putJson($path);
    $second = $this->actingAs($actor)->putJson($path);

    expect($second->json('data.retired_at'))->toBe($first->json('data.retired_at'));
});

it('publishes no route that deletes an extension outright', function () {
    $seed = seeded();

    $this->actingAs(actor([Scope::MANAGE]))
        ->deleteJson(api("extensions/{$seed['extension_id']}"))
        ->assertNotFound();
});
