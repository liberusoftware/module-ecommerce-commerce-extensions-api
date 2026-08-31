<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api;

/**
 * The OpenAPI 3.1 document this package publishes.
 *
 * A static file, not something generated from the router, so parity can be
 * asserted in both directions: a route with no operation is undocumented, an
 * operation with no route is a promise nothing keeps.
 */
final class OpenApi
{
    public static function path(): string
    {
        return __DIR__.'/../resources/openapi/openapi.json';
    }

    /** @return array<string, mixed> */
    public static function document(): array
    {
        $decoded = json_decode((string) file_get_contents(self::path()), true, flags: JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
