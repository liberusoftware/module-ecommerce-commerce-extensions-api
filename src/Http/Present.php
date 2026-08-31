<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\CommerceExtensions\Data\AttemptReport;
use Liberu\Ecommerce\CommerceExtensions\Data\IssuedSecret;
use Liberu\Ecommerce\CommerceExtensions\Data\RaisedDelivery;
use Liberu\Ecommerce\CommerceExtensions\Support\Cast;

/**
 * The only place a domain object becomes JSON.
 *
 * The domain's read queries hand back Eloquent builders, so rows flow through
 * this package whether it names their classes or not — and an `-api` adapter
 * may not import a domain `Models\` class. They flow as opaque handles read
 * through `Model::getAttribute()`, which is the framework's API rather than the
 * domain's, so this package depends on the attribute names the domain
 * publishes and on nothing about how they are stored, cast or related.
 *
 * Three things never leave:
 *
 * - **The signing secret.** `secret` and `previous_secret` are on the endpoint
 *   row and `getAttribute()` would read either straight past `$hidden`. No
 *   method here touches them. The only secret this surface publishes is the one
 *   `IssuedSecret` hands back at the moment it is minted.
 * - **The merchant.** `IssuedSecret`, `RaisedDelivery` and `AttemptReport` all
 *   carry it, and they are right to. Echoing it reads as though a caller could
 *   have chosen it, and this surface derives it from the credential.
 * - **The payload and the subject, in a listing.** The bytes a caller stored
 *   are opaque and may be anything; the host this module replaces put a
 *   customer's email address in them. Both are on the single-delivery read.
 */
final class Present
{
    /** @return array<string, mixed> */
    public static function extension(Model $extension): array
    {
        return [
            'id' => Cast::int($extension->getAttribute('id')),
            'extension_ref' => self::scalar($extension->getAttribute('extension_ref')),
            'name' => self::scalar($extension->getAttribute('name')),
            'retired_at' => self::instant($extension->getAttribute('retired_at')),
            'live' => $extension->getAttribute('retired_at') === null,
        ];
    }

    /** @return array<string, mixed> */
    public static function endpoint(Model $endpoint): array
    {
        return [
            'id' => Cast::int($endpoint->getAttribute('id')),
            'extension_id' => Cast::int($endpoint->getAttribute('extension_id')),
            'url' => self::scalar($endpoint->getAttribute('url')),
            'retired_at' => self::instant($endpoint->getAttribute('retired_at')),
            'live' => $endpoint->getAttribute('retired_at') === null,
            'previous_secret_expires_at' => self::instant($endpoint->getAttribute('previous_secret_expires_at')),
        ];
    }

    /** @return array<string, mixed> */
    public static function eventName(Model $eventName): array
    {
        return [
            'id' => Cast::int($eventName->getAttribute('id')),
            'name' => self::scalar($eventName->getAttribute('name')),
            'description' => self::nullableString($eventName->getAttribute('description')),
        ];
    }

    /** @return array<string, mixed> */
    public static function subscription(Model $subscription): array
    {
        return [
            'id' => Cast::int($subscription->getAttribute('id')),
            'endpoint_id' => Cast::int($subscription->getAttribute('endpoint_id')),
            'event_name' => self::scalar($subscription->getAttribute('event_name')),
        ];
    }

    /**
     * One delivery in a listing.
     *
     * `outcome` is null while the delivery is still owed, which is not the same
     * as having no outcome, so `settled` says which.
     *
     * @return array<string, mixed>
     */
    public static function delivery(Model $delivery): array
    {
        return [
            'id' => Cast::int($delivery->getAttribute('id')),
            'delivery_ref' => self::scalar($delivery->getAttribute('delivery_ref')),
            'event_name' => self::scalar($delivery->getAttribute('event_name')),
            'cause_ref' => self::scalar($delivery->getAttribute('cause_ref')),
            'endpoint_id' => Cast::int($delivery->getAttribute('endpoint_id')),
            'extension_id' => Cast::int($delivery->getAttribute('extension_id')),
            'raised_at' => self::instant($delivery->getAttribute('raised_at')),
            'expires_at' => self::instant($delivery->getAttribute('expires_at')),
            'next_attempt_at' => self::instant($delivery->getAttribute('next_attempt_at')),
            'settled_at' => self::instant($delivery->getAttribute('settled_at')),
            'settled' => $delivery->getAttribute('settled_at') !== null,
            'outcome' => self::nullableString($delivery->getAttribute('outcome')),
            'redacted_at' => self::instant($delivery->getAttribute('redacted_at')),
            'attempt_count' => self::nullableInt($delivery->getAttribute('attempts_count')),
        ];
    }

    /**
     * One delivery, with the bytes that were sent for it and every attempt.
     *
     * @param  list<array<string, mixed>>  $attempts
     * @return array<string, mixed>
     */
    public static function deliveryDetail(Model $delivery, array $attempts): array
    {
        return array_merge(self::delivery($delivery), [
            'subject_ref' => self::nullableString($delivery->getAttribute('subject_ref')),
            'payload' => self::nullableString($delivery->getAttribute('payload')),
            'attempt_count' => count($attempts),
            'attempts' => $attempts,
        ]);
    }

    /**
     * One attempt.
     *
     * `excerpt` with `transport_completed` false is an exception message and
     * not a response body — the request never finished — which is why the
     * field is not called `response`.
     *
     * A duration is only published when the transport completed. The module
     * stores `0` for a request that threw, and a zero substituted for an
     * unknown is a measurement nobody took.
     *
     * @return array<string, mixed>
     */
    public static function attempt(Model $attempt): array
    {
        $status = $attempt->getAttribute('response_status');

        return [
            'id' => Cast::int($attempt->getAttribute('id')),
            'sequence' => self::nullableInt($attempt->getAttribute('sequence')),
            'attempted_at' => self::instant($attempt->getAttribute('attempted_at')),
            'settled_at' => self::instant($attempt->getAttribute('settled_at')),
            'outcome' => self::scalar($attempt->getAttribute('outcome')),
            'refusal_reason' => self::nullableString($attempt->getAttribute('refusal_reason')),
            'response_status' => self::nullableInt($status),
            'transport_completed' => $status !== null,
            'excerpt' => self::nullableString($attempt->getAttribute('response_excerpt')),
            'duration_ms' => $status === null ? null : self::nullableInt($attempt->getAttribute('duration_ms')),
        ];
    }

    /**
     * A secret, at the one moment it exists outside the module.
     *
     * Nothing reads one back: both columns are encrypted and hidden, and there
     * is no query that returns one. A caller that loses this response has to
     * rotate.
     *
     * @return array<string, mixed>
     */
    public static function issuedSecret(IssuedSecret $issued): array
    {
        return [
            'endpoint_id' => $issued->endpointId,
            'secret' => $issued->secret,
            'previous_secret_expires_at' => self::instant($issued->previousSecretExpiresAt),
        ];
    }

    /** @return array<string, mixed> */
    public static function raised(RaisedDelivery $raised): array
    {
        return [
            'delivery_id' => $raised->deliveryId,
            'endpoint_id' => $raised->endpointId,
            'delivery_ref' => $raised->deliveryRef,
            'already_raised' => $raised->alreadyRaised,
        ];
    }

    /**
     * What one attempt did.
     *
     * `attempt_id` is null only when another worker holds the sequence, which
     * is the one refusal that writes no row. `delivery_outcome` null means the
     * delivery is still owed rather than that it has no outcome, so
     * `delivery_settled` carries that distinction as a field.
     *
     * @return array<string, mixed>
     */
    public static function report(AttemptReport $report): array
    {
        return [
            'delivery_id' => $report->deliveryId,
            'attempt_id' => $report->attemptId,
            'sequence' => $report->sequence,
            'outcome' => $report->outcome->value,
            'refusal_reason' => $report->refusalReason?->value,
            'response_status' => $report->responseStatus,
            'delivery_settled' => $report->deliveryOutcome !== null,
            'delivery_outcome' => $report->deliveryOutcome?->value,
        ];
    }

    /**
     * A page of a listing.
     *
     * `has_more` rather than a total: a count over the delivery log is a table
     * scan nobody asked for, and no caller here needs to render a page count.
     *
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public static function page(array $data, int $page, int $perPage, bool $hasMore): array
    {
        return [
            'data' => $data,
            'meta' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $hasMore],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public static function collection(array $data): array
    {
        return ['data' => $data];
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::scalar($value);
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : Cast::int($value);
    }

    /** Several columns carry a backed enum, so an attribute read is not always a string. */
    private static function scalar(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function instant(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value === null ? null : self::scalar($value);
    }
}
