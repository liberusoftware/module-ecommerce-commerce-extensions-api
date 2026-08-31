<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterExtension;
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
