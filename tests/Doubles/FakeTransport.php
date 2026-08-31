<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\CommerceExtensions\Api\Tests\Doubles;

use Liberu\Ecommerce\CommerceExtensions\Contracts\DeliveryTransport;
use Liberu\Ecommerce\CommerceExtensions\Data\SignedRequest;
use Liberu\Ecommerce\CommerceExtensions\Data\TransportResponse;
use RuntimeException;

final class FakeTransport implements DeliveryTransport
{
    /** @var list<SignedRequest> */
    public array $sent = [];

    public function __construct(
        private readonly ?int $status = 200,
        private readonly ?string $body = 'ok',
        private readonly bool $throws = false,
    ) {}

    public function send(SignedRequest $request): TransportResponse
    {
        $this->sent[] = $request;

        if ($this->throws) {
            throw new RuntimeException('connection refused');
        }

        return new TransportResponse($this->status, $this->body, 12);
    }
}
