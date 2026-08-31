<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterEventName;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Present;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Queries\ListEventNames;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;

/**
 * The catalogue an endpoint may subscribe to at all.
 *
 * There is no retirement verb, because the domain publishes none: an event name
 * is registered or it is not.
 */
final class EventNameController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'index' => Scope::READ,
        'store' => Scope::MANAGE,
    ];

    public function index(HttpRequest $request, ListEventNames $names): JsonResponse
    {
        return new JsonResponse($this->paged($request, $names($this->tenantId()), Present::eventName(...)));
    }

    public function store(HttpRequest $request, RegisterEventName $register): JsonResponse
    {
        $input = $this->validated($request, [
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:191'],
        ]);

        $description = isset($input['description']) ? Cast::str($input['description']) : null;

        return new JsonResponse(
            ['data' => Present::eventName($register($this->tenantId(), Cast::str($input['name']), $description))],
            201,
        );
    }
}
