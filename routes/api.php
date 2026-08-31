<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\DeliveryController;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\EndpointController;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\EventController;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\EventNameController;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\ExtensionController;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers\SubscriptionController;

/*
 * Nineteen endpoints, and the merchant appears in none of them.
 *
 * No route takes a tenant identifier in a path, a query string or a body: it is
 * derived from the authenticated actor, once, in the base controller. A
 * caller-supplied merchant is how one storefront's credential would point
 * another business's events at a destination of its own.
 *
 * Nothing is bound as a route model. Type-hinting a domain model in a route
 * signature would couple the transport to the domain's storage and would fetch
 * the row *before* asserting custody, which is the one way this surface could
 * start answering 403 where it must answer 404.
 *
 * ## What is deliberately absent
 *
 * **No delete, anywhere.** Retiring an extension or an endpoint is a dated fact
 * on a sub-resource — `PUT` to retire, `DELETE` to reinstate — because deleting
 * either would take the record of what it received with it. That is the host
 * defect this module was extracted to end, and a `DELETE extensions/{id}` would
 * be it rebuilt in the transport.
 *
 * **No privacy endpoint.** The domain's export and erasure both span every
 * merchant on the deployment, deliberately, because a person is a customer of
 * whichever merchants they choose. This surface holds one merchant's
 * credential, so publishing either would let that merchant read another's
 * payloads or destroy another's delivery log. `docs/domain.md` records the
 * tenant-scoped query that would bring them here.
 *
 * **No retention endpoint.** Pruning settled deliveries is the host's policy,
 * and the module ships a command and the schedule line for it. An HTTP verb
 * that deletes a security log is a worse way to reach the same job.
 *
 * **No re-open, no manifest, no install, no scope grant, no rollback, no health
 * model and no UI-extension registry**, because the domain publishes none of
 * them and a surface for something that does not exist is a promise nothing
 * keeps.
 */

Route::get('extensions', [ExtensionController::class, 'index'])->name('extensions.index');
Route::post('extensions', [ExtensionController::class, 'store'])->name('extensions.store');
Route::put('extensions/{extension}/retirement', [ExtensionController::class, 'retire'])
    ->whereNumber('extension')->name('extensions.retirement.update');
Route::delete('extensions/{extension}/retirement', [ExtensionController::class, 'reinstate'])
    ->whereNumber('extension')->name('extensions.retirement.destroy');

Route::get('endpoints', [EndpointController::class, 'index'])->name('endpoints.index');
Route::post('endpoints', [EndpointController::class, 'store'])->name('endpoints.store');
Route::put('endpoints/{endpoint}/retirement', [EndpointController::class, 'retire'])
    ->whereNumber('endpoint')->name('endpoints.retirement.update');
Route::delete('endpoints/{endpoint}/retirement', [EndpointController::class, 'reinstate'])
    ->whereNumber('endpoint')->name('endpoints.retirement.destroy');

/*
 * A rotation issues a secret and a rotated-out one is expired by deleting it.
 * Neither has a `GET`: nothing in the module reads a secret back, and a route
 * that appeared to would be a promise this package cannot keep.
 */
Route::post('endpoints/{endpoint}/secret-rotations', [EndpointController::class, 'rotate'])
    ->whereNumber('endpoint')->name('endpoints.secret-rotations.store');
Route::delete('endpoints/{endpoint}/previous-secret', [EndpointController::class, 'expirePrevious'])
    ->whereNumber('endpoint')->name('endpoints.previous-secret.destroy');

Route::post('endpoints/{endpoint}/subscriptions', [SubscriptionController::class, 'store'])
    ->whereNumber('endpoint')->name('endpoints.subscriptions.store');
Route::delete('endpoints/{endpoint}/subscriptions/{eventName}', [SubscriptionController::class, 'destroy'])
    ->whereNumber('endpoint')->where('eventName', '[A-Za-z0-9._-]+')->name('endpoints.subscriptions.destroy');

Route::get('event-names', [EventNameController::class, 'index'])->name('event-names.index');
Route::post('event-names', [EventNameController::class, 'store'])->name('event-names.store');

/*
 * The one endpoint that may name a subject, because the cause of an event is
 * outside this module and so is the person it concerns. The base controller
 * refuses a named subject under every other ability.
 */
Route::post('events', [EventController::class, 'raise'])->name('events.store');

// Before the `{delivery}` route so the intent is on the page, though the
// numeric constraint below is what actually keeps them apart.
Route::get('deliveries/due', [DeliveryController::class, 'due'])->name('deliveries.due.index');
Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
Route::get('deliveries/{delivery}', [DeliveryController::class, 'show'])
    ->whereNumber('delivery')->name('deliveries.show');
Route::post('deliveries/{delivery}/attempts', [DeliveryController::class, 'attempt'])
    ->whereNumber('delivery')->name('deliveries.attempts.store');
