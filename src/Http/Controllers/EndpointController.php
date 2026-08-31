<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Liberu\Ecommerce\CommerceExtensions\Actions\AddEndpoint;
use Liberu\Ecommerce\CommerceExtensions\Actions\ExpirePreviousSecret;
use Liberu\Ecommerce\CommerceExtensions\Actions\ReinstateEndpoint;
use Liberu\Ecommerce\CommerceExtensions\Actions\RetireEndpoint;
use Liberu\Ecommerce\CommerceExtensions\Actions\RotateSecret;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Present;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Queries\ListEndpoints;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;

/**
 * One URL an extension receives at, and the secret its requests are signed with.
 *
 * **The secret is in the response body of `store` and `rotate` and nowhere
 * else.** Nothing in the module reads one back — both columns are encrypted and
 * hidden — so a caller that loses either response has to rotate. That is also
 * why registering the same URL twice is a 409 rather than the endpoint that
 * exists: answering a retry would mean this surface had stored a one-time
 * secret in order to serve it again.
 */
final class EndpointController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'index' => Scope::READ,
        'store' => Scope::MANAGE,
        'retire' => Scope::MANAGE,
        'reinstate' => Scope::MANAGE,
        'rotate' => Scope::MANAGE,
        'expirePrevious' => Scope::MANAGE,
    ];

    public function index(HttpRequest $request, ListEndpoints $endpoints): JsonResponse
    {
        $input = $this->validated($request, ['extension' => ['integer', 'min:1']]);
        $extensionId = isset($input['extension']) ? Cast::int($input['extension']) : null;

        return new JsonResponse($this->paged(
            $request,
            $endpoints($this->tenantId(), $extensionId),
            Present::endpoint(...),
        ));
    }

    /** 201, and the only response in this package that carries a new secret. */
    public function store(HttpRequest $request, AddEndpoint $add): JsonResponse
    {
        $input = $this->validated($request, [
            'extension_id' => ['required', 'integer', 'min:1'],
            'url' => ['required', 'string', 'max:1024'],
        ]);

        $issued = $add($this->tenantId(), Cast::int($input['extension_id']), Cast::str($input['url']));

        return new JsonResponse(['data' => Present::issuedSecret($issued)], 201);
    }

    public function retire(string $endpoint, RetireEndpoint $retire): JsonResponse
    {
        return new JsonResponse(['data' => Present::endpoint(
            $retire($this->tenantId(), (int) $endpoint, $this->now()),
        )]);
    }

    public function reinstate(string $endpoint, ReinstateEndpoint $reinstate): JsonResponse
    {
        return new JsonResponse(['data' => Present::endpoint(
            $reinstate($this->tenantId(), (int) $endpoint),
        )]);
    }

    /**
     * 200, not 201: a rotation creates nothing addressable, and a `Location`
     * pointing at a secret is the one header this module must never write.
     *
     * The previous secret stays live for a bounded overlap and every request is
     * signed under both, so a receiver that has not picked the new one up keeps
     * verifying.
     */
    public function rotate(string $endpoint, RotateSecret $rotate): JsonResponse
    {
        return new JsonResponse(['data' => Present::issuedSecret(
            $rotate($this->tenantId(), (int) $endpoint, $this->now()),
        )]);
    }

    /** Closing the overlap early, for the leak that cannot wait for the window. */
    public function expirePrevious(string $endpoint, ExpirePreviousSecret $expire): JsonResponse
    {
        return new JsonResponse(['data' => Present::endpoint(
            $expire($this->tenantId(), (int) $endpoint),
        )]);
    }
}
