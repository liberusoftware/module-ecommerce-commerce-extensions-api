<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Liberu\Ecommerce\CommerceExtensions\Actions\RegisterExtension;
use Liberu\Ecommerce\CommerceExtensions\Actions\ReinstateExtension;
use Liberu\Ecommerce\CommerceExtensions\Actions\RetireExtension;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Present;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Queries\ListExtensions;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;

/**
 * A merchant's registration of a third party that receives events.
 *
 * A record, never a PHP service provider: nothing here installs, updates or
 * uninstalls code, and `liberusoftware/module-manager` remains the only thing
 * that decides which providers boot.
 *
 * Retirement is a sub-resource because retiring is not deleting. There is no
 * `DELETE extensions/{id}` and there is not going to be one: deleting an
 * extension would take the record of what it received with it, which is the
 * host defect this module was extracted to end.
 */
final class ExtensionController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'index' => Scope::READ,
        'store' => Scope::MANAGE,
        'retire' => Scope::MANAGE,
        'reinstate' => Scope::MANAGE,
    ];

    public function index(HttpRequest $request, ListExtensions $extensions): JsonResponse
    {
        $this->validated($request, ['live' => ['boolean']]);

        return new JsonResponse($this->paged(
            $request,
            $extensions($this->tenantId(), $request->boolean('live')),
            Present::extension(...),
        ));
    }

    public function store(HttpRequest $request, RegisterExtension $register): JsonResponse
    {
        $input = $this->validated($request, [
            'extension_ref' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:191'],
        ]);

        $extension = $register($this->tenantId(), Cast::str($input['extension_ref']), Cast::str($input['name']));

        return new JsonResponse(['data' => Present::extension($extension)], 201);
    }

    /** Silences a partner without deleting the evidence of what it received. */
    public function retire(string $extension, RetireExtension $retire): JsonResponse
    {
        return new JsonResponse(['data' => Present::extension(
            $retire($this->tenantId(), (int) $extension, $this->now()),
        )]);
    }

    /** Without this a mis-clicked retirement is permanent: the natural key refuses a re-registration. */
    public function reinstate(string $extension, ReinstateExtension $reinstate): JsonResponse
    {
        return new JsonResponse(['data' => Present::extension(
            $reinstate($this->tenantId(), (int) $extension),
        )]);
    }
}
