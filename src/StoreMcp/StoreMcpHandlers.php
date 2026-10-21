<?php

declare(strict_types=1);

namespace OneTrace\Laravel\StoreMcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Mail;
use OneTrace\StoreMcp\ToolError;

/**
 * Tools of the store MCP server (store MCP contract v1). Extend it and implement the methods of the tools you
 * offer — a tool without a method is not offered:
 *
 *   getCustomer(Context $ctx), findOrders(array $args, Context $ctx), getOrder, getTracking, checkAvailability,
 *   getDeliveryOptions, getReturnPolicy, cancelOrder, createReturn, changeDelivery — each returns the result array
 *   of the contract or throws OneTrace\StoreMcp\ToolError; $ctx->customerId is the confirmed customer.
 *
 * findCustomer() turns on confirmation codes; sendCode() sends a code by email (Mail) — override it for SMS.
 */
abstract class StoreMcpHandlers
{
    /**
     * The customer id of the signed-in user for the chat token (@onetrace prints cdp.chatCustomer()).
     */
    public function customerId(Authenticatable $user): ?string
    {
        $id = $user->getAuthIdentifier();

        return \is_scalar($id) ? (string) $id : null;
    }

    /**
     * The customer with this email or phone (and the order number if the customer gave one), null if unknown.
     * Null by default: confirmation by code is off.
     */
    public function findCustomer(string $contact, ?string $order): ?string
    {
        return null;
    }

    public function sendCode(string $customerId, string $contact, string $code, string $channel): void
    {
        if ($channel !== 'email') {
            throw new ToolError(ToolError::UNAVAILABLE, 'Codes by SMS are not available.');
        }

        $text = __('Your confirmation code: :code. It is valid for 10 minutes.', ['code' => $code]);
        $subject = __('Confirmation code');

        Mail::raw(\is_string($text) ? $text : "Your confirmation code: {$code}", static function ($message) use ($contact, $subject): void {
            $message->to($contact)->subject(\is_string($subject) ? $subject : 'Confirmation code');
        });
    }

    /**
     * @internal Whether confirmation by code is implemented.
     */
    public function verifiesByCode(): bool
    {
        return (new \ReflectionMethod($this, 'findCustomer'))->getDeclaringClass()->getName() !== self::class;
    }
}
