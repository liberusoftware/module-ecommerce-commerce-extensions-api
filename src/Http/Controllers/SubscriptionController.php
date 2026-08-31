<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Response;
use Liberu\Ecommerce\CommerceExtensions\Actions\Subscribe;
use Liberu\Ecommerce\CommerceExtensions\Actions\Unsubscribe;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Present;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;

/**
 * Which registered event names an endpoint receives.
 *
 * Unsubscribing something that was not held answers 404, exactly as
 * unsubscribing from an endpoint belonging to another merchant does — the
 * domain's delete states the tenant and the endpoint and reports the same
 * `false` either way, so neither answer tells a caller which of the two it was.
 * A silent 204 would make a mistyped name look like success, which is the host
 * defect this module's registry exists to end, one level up.
 */
final class SubscriptionController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'store' => Scope::MANAGE,
        'destroy' => Scope::MANAGE,
    ];

    public function store(HttpRequest $request, string $endpoint, Subscribe $subscribe): JsonResponse
    {
        $input = $this->validated($request, ['event_name' => ['required', 'string', 'max:128']]);

        $subscription = $subscribe($this->tenantId(), (int) $endpoint, Cast::str($input['event_name']));

        return new JsonResponse(['data' => Present::subscription($subscription)], 201);
    }

    public function destroy(string $endpoint, string $eventName, Unsubscribe $unsubscribe): JsonResponse
    {
        if (! $unsubscribe($this->tenantId(), (int) $endpoint, $eventName)) {
            throw new ModelNotFoundException();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
