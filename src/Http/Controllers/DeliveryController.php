<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\CommerceExtensions\Actions\AttemptDelivery;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Present;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Enums\DeliveryOutcome;
use Liberu\Ecommerce\CommerceExtensions\Queries\ListDeliveries;
use Liberu\Ecommerce\CommerceExtensions\Queries\ListDueDeliveries;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;

/**
 * The delivery log, and the one verb that acts on it.
 *
 * `attempt` answers **200 for every report, including a refusal**. A refusal is
 * a row with a reason — nothing bound to send with, an endpoint or extension
 * retired, a destination that now resolves into private space, the window
 * closed, another worker already holding the sequence — and rendering one as a
 * 5xx would turn a recorded fact into an alert. A caller branches on `outcome`
 * and `refusal_reason`. A settled delivery is the exception: there is no verb
 * that reopens one, so attempting it again is a 409.
 *
 * The listing publishes neither the stored payload nor the subject reference.
 * Both are on the single-delivery read, and both are opaque bytes a caller
 * chose: the host this module replaces put a customer's email address in them.
 */
final class DeliveryController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = [
        'index' => Scope::READ,
        'show' => Scope::READ,
        'due' => Scope::DELIVER,
        'attempt' => Scope::DELIVER,
    ];

    public function index(HttpRequest $request, ListDeliveries $deliveries): JsonResponse
    {
        $input = $this->validated($request, [
            'endpoint' => ['integer', 'min:1'],
            'outcome' => ['string', 'in:'.implode(',', array_column(DeliveryOutcome::cases(), 'value'))],
        ]);

        $query = $deliveries(
            $this->tenantId(),
            isset($input['endpoint']) ? Cast::int($input['endpoint']) : null,
            isset($input['outcome']) ? DeliveryOutcome::from(Cast::str($input['outcome'])) : null,
        );

        $query->withCount('attempts');

        // One raise fans out to every subscribed endpoint at the same instant,
        // so `raised_at` alone is not a total order and paging over it would
        // repeat and drop rows.
        $query->orderBy('id', 'desc');

        return new JsonResponse($this->paged($request, $query, Present::delivery(...)));
    }

    /** One delivery, the bytes that were sent for it, and every attempt in order. */
    public function show(string $delivery, ListDeliveries $deliveries): JsonResponse
    {
        $found = $deliveries($this->tenantId())->whereKey((int) $delivery)->firstOrFail();

        $attempts = [];

        foreach ($found->attempts()->orderBy('id')->get() as $attempt) {
            $attempts[] = Present::attempt($attempt);
        }

        return new JsonResponse(['data' => Present::deliveryDetail($found, $attempts)]);
    }

    /** What a worker asks for: one indexed, bounded query per merchant. */
    public function due(HttpRequest $request, ListDueDeliveries $due): JsonResponse
    {
        $maximum = Cast::int(Config::get('commerce-extensions-api.due.max_limit'), 500);

        $input = $this->validated($request, ['limit' => ['integer', 'min:1', 'max:'.$maximum]]);

        $query = $due(
            $this->tenantId(),
            $this->now(),
            Cast::int($input['limit'] ?? null, Cast::int(Config::get('commerce-extensions-api.due.default_limit'), 100)),
        );

        $query->withCount('attempts');

        $data = [];

        foreach ($query->get() as $delivery) {
            $data[] = Present::delivery($delivery);
        }

        return new JsonResponse(Present::collection($data));
    }

    public function attempt(string $delivery, AttemptDelivery $attempt): JsonResponse
    {
        return new JsonResponse(['data' => Present::report(
            $attempt($this->tenantId(), (int) $delivery, $this->now()),
        )]);
    }
}
