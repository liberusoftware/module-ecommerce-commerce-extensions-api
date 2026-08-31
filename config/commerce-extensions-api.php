<?php

declare(strict_types=1);

return [
    // The group's middleware is `[]` and never null: an empty stack is a host
    // that has not opted in to one, a null stack is Laravel substituting one.
    // Every endpoint refuses an unauthenticated caller in the controller
    // regardless.
    'route' => [
        'prefix' => 'api/commerce-extensions',
        'middleware' => [],
        'domain' => null,
    ],

    // The merchant is read from the authenticated actor and never from the
    // request. Which attribute holds it is host-specific.
    'actor' => [
        'tenant_attribute' => 'team_id',
    ],

    'listing' => [
        'default_per_page' => 50,
        'max_per_page' => 200,
    ],

    'due' => [
        'default_limit' => 100,
        'max_limit' => 500,
    ],

    // The module stores a payload in a `text` column and does not bound it. A
    // host on a wider column raises this; the point is that too large is a 422
    // here rather than a truncated body or a 500 at the write.
    'payload' => [
        'max_bytes' => 65535,
    ],
];
