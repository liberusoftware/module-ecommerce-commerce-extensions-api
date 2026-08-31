<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The credential this surface is called with.
 *
 * It carries a real `tokenCan()`, so `method_exists()` answers honestly where
 * `is_callable()` would answer true for any Eloquent model at all, and it holds
 * the merchant on an attribute the package reads by configured name.
 *
 * @property string|null $team_id
 * @property list<string> $abilities
 */
class ApiActor extends Authenticatable
{
    protected $table = 'api_actors';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['abilities' => 'array'];
    }

    public function tokenCan(string $ability): bool
    {
        return in_array($ability, (array) $this->getAttribute('abilities'), true);
    }
}
