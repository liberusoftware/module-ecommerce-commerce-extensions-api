<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Http;

use LogicException;

/**
 * The four abilities this surface publishes.
 *
 * There is no shopper ability, because nothing here is a shopper's: a browser
 * has no business registering a third party that receives a merchant's events.
 * The line that matters runs between configuring where events go and raising
 * one — a service that raises `order.paid` must not be able to point it at a
 * destination of its own.
 */
final class Scope
{
    /** Extensions, endpoints, event names, deliveries and attempts. Writes nothing. */
    public const READ = 'commerce-extensions:read';

    /** Registering, retiring, subscribing, and issuing or rotating a signing secret. */
    public const MANAGE = 'commerce-extensions:manage';

    /** Raising an event, which is the only ability that may name a subject. */
    public const RAISE = 'commerce-extensions:raise';

    /** What a worker holds: the due list, and attempting one delivery. */
    public const DELIVER = 'commerce-extensions:deliver';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::READ, self::MANAGE, self::RAISE, self::DELIVER];
    }

    /**
     * The abilities under which a caller may name a subject.
     *
     * The cause of an event is outside this module, so its subject arrives in a
     * body. Every other ability is refused one in the base controller, so an
     * endpoint that gained a `subject_ref` parameter would fail rather than
     * quietly widen.
     *
     * @return list<string>
     */
    public static function maySubject(): array
    {
        return [self::RAISE];
    }

    /** The guard the base controller applies before it reads a subject out of a body. */
    public static function refuseSubjectUnder(string $ability): void
    {
        if (! in_array($ability, self::maySubject(), true)) {
            throw new LogicException("The [{$ability}] ability may not name a subject.");
        }
    }
}
