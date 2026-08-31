<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Liberu\Ecommerce\CommerceExtensions\Actions\RaiseEvent;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Present;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;

/**
 * Raising one event, which is the whole reason the module exists.
 *
 * The caller hands in a name, a cause, a subject and already-serialised bytes.
 * This surface never builds a payload, never names an order and never touches
 * money — the host's job did all three and lost the pence on the way out.
 *
 * The cause reference is the natural key and is the caller's own. Raising the
 * same cause for the same subject is a **replay**: the deliveries that already
 * exist come back with `already_raised` and the status is 200, because nothing
 * was created. Raising it for a *different* subject is 409, never the other
 * subject's row. There is no idempotency key over the top of that: a key a
 * client holds is a key a client can change, and this one already exists.
 *
 * An event nobody subscribes to is an empty list and a 200. It is not an error,
 * and it must not be rendered as a failure.
 */
final class EventController extends Controller
{
    /** @var array<string, string> */
    protected array $scopes = ['raise' => Scope::RAISE];

    public function raise(HttpRequest $request, RaiseEvent $raise): JsonResponse
    {
        $input = $this->validated($request, [
            'event_name' => ['required', 'string', 'max:128'],
            'cause_ref' => ['required', 'string', 'max:128'],
            'subject_ref' => ['nullable', 'string', 'max:128'],
            'payload' => ['present', 'string'],
        ]);

        $payload = Cast::str($input['payload']);
        $maximum = Cast::int(Config::get('commerce-extensions-api.payload.max_bytes'), 65535);

        // Bytes, not characters: the module stores the payload in a text column
        // and does not bound it, so an over-long one is a truncated body or a
        // failed write rather than an answer.
        if (strlen($payload) > $maximum) {
            throw ValidationException::withMessages([
                'payload' => "A payload may be at most {$maximum} bytes on this deployment.",
            ]);
        }

        $raised = $raise(
            $this->tenantId(),
            Cast::str($input['event_name']),
            Cast::str($input['cause_ref']),
            $this->namedSubject($input),
            $payload,
            $this->now(),
        );

        $data = [];
        $created = false;

        foreach ($raised as $delivery) {
            $data[] = Present::raised($delivery);
            $created = $created || ! $delivery->alreadyRaised;
        }

        return new JsonResponse(Present::collection($data), $created ? 201 : 200);
    }
}
