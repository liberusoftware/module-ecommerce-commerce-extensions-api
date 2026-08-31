<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Actions\AddEndpoint;
use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterEventName;
use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterExtension;
use Liberu\Ecommerce\CommerceExtensions\Actions\Subscribe;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;

/*
 * A row past the page is fetched rather than counted. `has_more` is what a
 * caller needs, and a `COUNT(*)` over the delivery log is a table scan nobody
 * asked for.
 */
it('pages a listing and says whether there is more', function () {
    foreach (['a', 'b', 'c'] as $reference) {
        (new RegisterExtension())('merchant-a', "partner-{$reference}", strtoupper($reference));
    }

    $actor = actor();

    $first = $this->actingAs($actor)->getJson(api('extensions?per_page=2'));
    $second = $this->actingAs($actor)->getJson(api('extensions?per_page=2&page=2'));

    expect($first->json('data'))->toHaveCount(2)
        ->and($first->json('meta'))->toBe(['page' => 1, 'per_page' => 2, 'has_more' => true])
        ->and($second->json('data'))->toHaveCount(1)
        ->and($second->json('meta'))->toBe(['page' => 2, 'per_page' => 2, 'has_more' => false])
        ->and($second->json('data.0.extension_ref'))->toBe('partner-c');
});

it('refuses a page size outside the bounds a host configured', function () {
    $actor = actor();

    $this->actingAs($actor)->getJson(api('extensions?per_page=0'))->assertStatus(422);
    $this->actingAs($actor)->getJson(api('extensions?per_page=201'))->assertStatus(422);
    $this->actingAs($actor)->getJson(api('extensions?page=0'))->assertStatus(422);
    $this->actingAs($actor)->getJson(api('deliveries/due?limit=501'))->assertStatus(403);
    $this->actingAs(actor([Scope::DELIVER]))->getJson(api('deliveries/due?limit=501'))->assertStatus(422);
});

it('defaults the page size when a caller asks for none', function () {
    seeded();

    expect($this->actingAs(actor())->getJson(api('extensions'))->json('meta.per_page'))->toBe(50);
});

/*
 * The domain orders extensions by name and deliveries by the instant they were
 * raised, and neither is a total order: two extensions may share a name, and
 * one raise fans out to every subscribed endpoint at the same instant. Paging
 * over a non-total order silently repeats one row and drops another, so this
 * surface adds the tiebreaker its own paging needs.
 */
it('pages a listing whose domain ordering is not total without repeating or dropping a row', function () {
    foreach (['a', 'b', 'c'] as $reference) {
        (new RegisterExtension())('merchant-a', "partner-{$reference}", 'The Same Name');
    }

    $actor = actor();
    $seen = [];

    foreach ([1, 2] as $page) {
        foreach ((array) $this->actingAs($actor)->getJson(api("extensions?per_page=2&page={$page}"))->json('data') as $row) {
            $seen[] = $row['id'];
        }
    }

    expect($seen)->toHaveCount(3)
        ->and(array_unique($seen))->toHaveCount(3);

    (new RegisterEventName())('merchant-a', 'order.paid');

    foreach (['https://203.0.113.40/hook', 'https://203.0.113.41/hook'] as $index => $url) {
        $extension = (new RegisterExtension())('merchant-a', "receiver-{$index}", 'A Receiver');
        $issued = (new AddEndpoint())('merchant-a', $extension->id, $url);
        (new Subscribe())('merchant-a', $issued->endpointId, 'order.paid');
    }

    $this->actingAs(actor([Scope::RAISE]))->postJson(api('events'), [
        'event_name' => 'order.paid',
        'cause_ref' => 'order-77',
        'subject_ref' => 'person-9',
        'payload' => '{}',
    ])->assertCreated();

    $deliveries = [];

    foreach ([1, 2] as $page) {
        foreach ((array) $this->actingAs($actor)->getJson(api("deliveries?per_page=1&page={$page}"))->json('data') as $row) {
            $deliveries[] = $row['id'];
        }
    }

    expect($deliveries)->toHaveCount(2)
        ->and(array_unique($deliveries))->toHaveCount(2);
});
