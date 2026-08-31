<?php

declare(strict_types=1);

use Liberu\Ecommerce\CommerceExtensions\Api\Http\Scope;

it('publishes four abilities and no more', function () {
    expect(Scope::all())->toBe([
        'commerce-extensions:read',
        'commerce-extensions:manage',
        'commerce-extensions:raise',
        'commerce-extensions:deliver',
    ]);
});

/*
 * The split is by whose subject it is. The cause of an event is outside this
 * module and so is the person it concerns, so raising one names a subject;
 * nothing else may, and the guard is a refusal rather than a convention.
 */
it('lets only the raise ability name a subject', function () {
    expect(Scope::maySubject())->toBe([Scope::RAISE]);

    Scope::refuseSubjectUnder(Scope::RAISE);

    foreach ([Scope::READ, Scope::MANAGE, Scope::DELIVER] as $ability) {
        expect(fn () => Scope::refuseSubjectUnder($ability))->toThrow(LogicException::class);
    }
});
