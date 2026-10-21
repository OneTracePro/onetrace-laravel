<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests\Fixtures;

use OneTrace\Laravel\StoreMcp\StoreMcpHandlers;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\ToolError;

final class StoreTools extends StoreMcpHandlers
{
    /** @var list<string> */
    public static array $codes = [];

    public function getCustomer(Context $ctx): array
    {
        return ['id' => $ctx->customerId, 'first_name' => 'Anna'];
    }

    public function getOrder(array $args, Context $ctx): array
    {
        if ($ctx->customerId !== '1' || ($args['order'] ?? null) !== '1001') {
            throw new ToolError(ToolError::NOT_FOUND, 'No such order.');
        }

        return ['order' => '1001', 'status' => 'shipped'];
    }

    public function cancelOrder(array $args, Context $ctx): array
    {
        return ['order' => $args['order'], 'status' => 'cancelled'];
    }

    public function findCustomer(string $contact, ?string $order): ?string
    {
        return $contact === 'anna@example.com' ? '1' : null;
    }

    public function sendCode(string $customerId, string $contact, string $code, string $channel): void
    {
        self::$codes[] = $code;
    }
}
