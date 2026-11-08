<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests\Fixtures;

use OneTrace\Laravel\StoreMcp\StoreMcpHandlers;
use OneTrace\StoreMcp\Context;

/**
 * A store without customer accounts that takes orders from AI shopping assistants (contract v2).
 */
final class OrderTools extends StoreMcpHandlers
{
    /** @var list<string> */
    public static array $codes = [];

    /** @var list<array<string, mixed>> */
    public static array $orders = [];

    /**
     * Signed-in customers only: the server answers a guest itself.
     */
    public function getCustomer(Context $ctx): array
    {
        return ['id' => $ctx->customerId];
    }

    public function quoteOrder(array $args, Context $ctx): array
    {
        $total = ['amount' => 25 * $args['items'][0]['quantity'], 'currency' => 'EUR'];

        return ['items' => $args['items'], 'unavailable' => [], 'subtotal' => $total, 'total' => $total, 'shipping' => $args['shipping']];
    }

    public function createOrder(array $args, Context $ctx): array
    {
        self::$orders[] = ['email' => $ctx->guestEmail(), 'source' => $ctx->source, 'assistant' => $ctx->assistant, 'shipping' => $args['shipping']];
        $number = (string) (1000 + \count(self::$orders));

        return ['order' => $number, 'status' => 'pending', 'total' => ['amount' => 50, 'currency' => 'EUR'], 'payment_status' => 'pending', 'payment_url' => "https://shop.example.com/pay/{$number}"];
    }

    public function sendCode(string $customerId, string $contact, string $code, string $channel): void
    {
        self::$codes[] = $code;
    }
}
