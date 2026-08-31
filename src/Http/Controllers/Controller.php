<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Failure;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Present;
use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;
use Throwable;

/**
 * The one place this surface decides who is asking, what a domain refusal means
 * over HTTP, and what time it is.
 *
 * `callAction()` rather than middleware: `Illuminate\Routing\Pipeline` renders
 * whatever a route throws through the application's handler before the
 * surrounding middleware resumes, so a middleware mapper sees a rendered 500
 * instead of the domain exception. This is inside the route and one frame above
 * the action.
 *
 * The ability check is `method_exists()` and never `is_callable()`: Eloquent
 * implements `__call`, so `is_callable([$user, 'tokenCan'])` is true for every
 * model there is.
 *
 * The merchant is read from the actor and never from the request. The subject
 * is read from a body only under an ability that may name one, and
 * `namedSubject()` refuses under any other — mechanically, so an endpoint that
 * gained a `subject_ref` parameter would fail rather than quietly widen.
 *
 * A reference belonging to another merchant answers 404, byte for byte as one
 * nobody ever minted does. Nothing here checks custody before a lookup: the
 * domain states the tenant on every query and raises one `ModelNotFoundException`
 * either way, and a 403 in front of it would publish which rows exist.
 */
abstract class Controller extends BaseController
{
    /**
     * The ability each action requires, keyed by method name.
     *
     * A method absent from this map is refused rather than allowed: an
     * unanswered authorization question is not a yes.
     *
     * @var array<string, string>
     */
    protected array $scopes = [];

    private string $tenantId = '';

    private string $ability = '';

    private ?CarbonImmutable $at = null;

    /**
     * @param  string  $method
     * @param  array<string, mixed>  $parameters
     */
    public function callAction($method, $parameters): mixed
    {
        $refusal = $this->resolveActor($method);

        if ($refusal instanceof JsonResponse) {
            return $refusal;
        }

        try {
            return parent::callAction($method, $parameters);
        } catch (ValidationException $exception) {
            $body = Failure::body('validation_failed', 'The request did not satisfy this endpoint.', true);
            $body['error']['fields'] = $exception->errors();

            return new JsonResponse($body, 422);
        } catch (Throwable $exception) {
            $mapping = Failure::for($exception);

            if ($mapping === null) {
                throw $exception;
            }

            return new JsonResponse(
                Failure::body($mapping['code'], $mapping['message'], $mapping['resubmittable']),
                $mapping['status'],
            );
        }
    }

    /** @return array<string, string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    protected function tenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * The instant this request acts at, read once.
     *
     * Six of the domain's entry points take a `CarbonImmutable`, and the domain
     * reads no clock of its own. One request that resolved `now()` twice could
     * raise a delivery whose window began before the attempt that is due at it.
     */
    protected function now(): CarbonImmutable
    {
        return $this->at ??= Carbon::now()->toImmutable();
    }

    /**
     * The person an event names, from validated input.
     *
     * @param  array<string, mixed>  $input
     */
    protected function namedSubject(array $input): ?string
    {
        Scope::refuseSubjectUnder($this->ability);

        $subject = $input['subject_ref'] ?? null;

        return $subject === null || $subject === '' ? null : Cast::str($subject);
    }

    /**
     * Validate, and hand back only what was validated.
     *
     * `Validator::make(...)` rather than `$request->validate()`: the latter is a
     * framework-foundation macro this package does not require.
     *
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>
     */
    protected function validated(HttpRequest $request, array $rules): array
    {
        /** @var array<string, mixed> */
        return Validator::make($request->all(), $rules)->validate();
    }

    /**
     * One page of a listing the domain built.
     *
     * A row past the page is fetched rather than counted: `has_more` is what a
     * caller needs, and a `COUNT(*)` over the delivery log is a table scan
     * nobody asked for.
     *
     * @param  Builder<covariant Model>  $query
     * @param  callable(Model): array<string, mixed>  $present
     * @return array<string, mixed>
     */
    protected function paged(HttpRequest $request, Builder $query, callable $present): array
    {
        $max = Cast::int(Config::get('commerce-extensions-api.listing.max_per_page'), 200);

        $input = $this->validated($request, [
            'page' => ['integer', 'min:1'],
            'per_page' => ['integer', 'min:1', 'max:'.$max],
        ]);

        $page = Cast::int($input['page'] ?? null, 1);
        $perPage = Cast::int(
            $input['per_page'] ?? null,
            Cast::int(Config::get('commerce-extensions-api.listing.default_per_page'), 50),
        );

        $query->skip(($page - 1) * $perPage);
        $query->take($perPage + 1);

        $data = [];

        foreach ($query->get() as $row) {
            $data[] = $present($row);
        }

        return Present::page(array_slice($data, 0, $perPage), $page, $perPage, count($data) > $perPage);
    }

    /**
     * Establish the actor, the merchant and the ability, or refuse.
     *
     * A `JsonResponse` rather than a throwable, so every refusal this surface
     * produces has one body shape.
     */
    private function resolveActor(string $method): ?JsonResponse
    {
        $scope = $this->scopes[$method] ?? null;

        if ($scope === null) {
            return $this->refuse(403, 'insufficient_scope', 'This operation publishes no ability and cannot be called.');
        }

        $user = Request::user();

        if (! $user instanceof Authenticatable) {
            return $this->refuse(401, 'unauthenticated', 'This endpoint requires an authenticated actor.');
        }

        if (! method_exists($user, 'tokenCan') || $user->tokenCan($scope) !== true) {
            return $this->refuse(403, 'insufficient_scope', "This credential does not carry the [{$scope}] ability.");
        }

        $tenantId = data_get($user, Cast::str(Config::get('commerce-extensions-api.actor.tenant_attribute')) ?: 'team_id');

        if (! is_scalar($tenantId) || (string) $tenantId === '') {
            return $this->refuse(403, 'actor_has_no_tenant', 'This credential is not attached to a merchant.');
        }

        $this->tenantId = (string) $tenantId;
        $this->ability = $scope;

        return null;
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(Failure::body($code, $message, false), $status);
    }
}
