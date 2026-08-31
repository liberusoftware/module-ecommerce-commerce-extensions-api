<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\AttemptsAreAppendOnly;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\CauseReferenceIsClaimed;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\DeliveryIsAlreadySettled;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\EndpointIsAlreadyRegistered;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\EndpointUrlIsInvalid;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\EventNameIsAlreadyRegistered;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\EventNameIsNotRegistered;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\ExtensionIsAlreadyRegistered;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\SubscriptionIsAlreadyHeld;
use Liberu\Ecommerce\CommerceExtensions\Exceptions\ThereIsNoPreviousSecret;
use Throwable;

/**
 * Every way a request to this surface can fail, in one table.
 *
 * A table rather than a chain of `instanceof` because two things are asserted
 * about it as a whole: every exception the domain publishes appears exactly
 * once, and every entry is classified. An unmapped throwable bubbles as a 500
 * rather than being dressed as a plausible 4xx.
 *
 * Nothing here is a 503. The domain's one seam refuses *inside* an
 * `AttemptReport` — an unbound transport is a recorded row with a reason, not a
 * thrown exception — so the honest answer is a 200 carrying that reason. See
 * `DeliveryController::attempt()`.
 *
 * Nothing here is a 403 either. A 403 for a reference belonging to somebody
 * else confirms it exists, which is the disclosure the 404 avoids. The 401 and
 * the three 403s this surface emits are about the credential itself and are
 * built in the base controller.
 */
final class Failure
{
    /**
     * The one answer for every reference this credential may not have.
     *
     * Every action and query in the domain is `where('tenant_id', …)` then
     * `firstOrFail()`, so another merchant's row and a row nobody ever created
     * raise the same `ModelNotFoundException`. This surface keeps them one
     * answer instead of putting the distinction back.
     *
     * @var array{status: int, code: string, message: string, resubmittable: bool}
     */
    private const NOT_FOUND = [
        'status' => 404,
        'code' => 'not_found',
        'message' => 'No such record exists for this credential.',
        'resubmittable' => false,
    ];

    /**
     * @var array<class-string, array{status: int, code: string, message: string, resubmittable: bool}>
     */
    private const MAP = [
        ModelNotFoundException::class => self::NOT_FOUND,

        // Input the caller can correct.
        EndpointUrlIsInvalid::class => [
            'status' => 422,
            'code' => 'endpoint_url_invalid',
            'message' => 'That URL was refused. An endpoint URL must be https, and must not resolve into private or reserved address space.',
            'resubmittable' => true,
        ],
        EventNameIsNotRegistered::class => [
            'status' => 422,
            'code' => 'event_name_not_registered',
            'message' => 'That event name is not registered for this merchant. Register it first: an unregistered name would be a subscription that quietly matches nothing.',
            'resubmittable' => true,
        ],

        // Decisions already made. Asking again does not change any of them.
        ExtensionIsAlreadyRegistered::class => [
            'status' => 409,
            'code' => 'extension_already_registered',
            'message' => 'This merchant already registered an extension under that reference.',
            'resubmittable' => false,
        ],
        EndpointIsAlreadyRegistered::class => [
            'status' => 409,
            'code' => 'endpoint_already_registered',
            'message' => 'This extension already has an endpoint at that URL. Its secret was issued once and cannot be issued again; rotate it instead.',
            'resubmittable' => false,
        ],
        EventNameIsAlreadyRegistered::class => [
            'status' => 409,
            'code' => 'event_name_already_registered',
            'message' => 'That event name is already registered for this merchant.',
            'resubmittable' => false,
        ],
        SubscriptionIsAlreadyHeld::class => [
            'status' => 409,
            'code' => 'subscription_already_held',
            'message' => 'This endpoint already subscribes to that event name.',
            'resubmittable' => false,
        ],
        CauseReferenceIsClaimed::class => [
            'status' => 409,
            'code' => 'cause_reference_claimed',
            'message' => 'That cause reference is already held on this endpoint by a different subject. The cause is the natural key, so it identifies one occurrence and cannot be reused for another.',
            'resubmittable' => false,
        ],
        DeliveryIsAlreadySettled::class => [
            'status' => 409,
            'code' => 'delivery_already_settled',
            'message' => 'That delivery has settled. There is no verb that reopens one: raise the event again under a new cause reference.',
            'resubmittable' => false,
        ],
        ThereIsNoPreviousSecret::class => [
            'status' => 409,
            'code' => 'no_previous_secret',
            'message' => 'This endpoint has no rotated-out secret to expire.',
            'resubmittable' => false,
        ],

        // Not reachable over this surface: every attempt row it writes is a new
        // one. Mapped so that a future path to it cannot arrive as a 500.
        AttemptsAreAppendOnly::class => [
            'status' => 409,
            'code' => 'attempts_are_append_only',
            'message' => 'A settled delivery attempt cannot be rewritten.',
            'resubmittable' => false,
        ],
    ];

    /** @return array<class-string, array{status: int, code: string, message: string, resubmittable: bool}> */
    public static function map(): array
    {
        return self::MAP;
    }

    /**
     * The mapping for a thrown exception, or null when this surface does not
     * own it.
     *
     * @return array{status: int, code: string, message: string, resubmittable: bool}|null
     */
    public static function for(Throwable $exception): ?array
    {
        foreach (self::MAP as $class => $mapping) {
            if ($exception instanceof $class) {
                return $mapping;
            }
        }

        return null;
    }

    /**
     * @return array{error: array{code: string, message: string, resubmittable: bool}}
     */
    public static function body(string $code, string $message, bool $resubmittable): array
    {
        return ['error' => ['code' => $code, 'message' => $message, 'resubmittable' => $resubmittable]];
    }
}
