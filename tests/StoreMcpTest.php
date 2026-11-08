<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use OneTrace\Laravel\StoreMcp\StoreMcp;
use OneTrace\Laravel\Tests\Fixtures\OrderTools;
use OneTrace\Laravel\Tests\Fixtures\StoreTools;
use OneTrace\Laravel\Tests\Fixtures\User;

final class StoreMcpTest extends TestCase
{
    private const KEY = 'platform-key-0123456789abcdef0123456789abcdef';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('onetrace.store_mcp', [
            'enabled' => true, 'path' => 'onetrace/mcp', 'key' => self::KEY, 'token_secret' => null, 'token_ttl' => 3600,
            'handlers' => StoreTools::class, 'tools' => null, 'cache' => 'array', 'name' => 'test-store',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', static function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = [], string $key = self::KEY): array
    {
        return $this->postJson('/onetrace/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['Authorization' => 'Bearer ' . $key])->assertOk()->json();
    }

    public function testServesTheContractWithReadToolsByDefault(): void
    {
        self::assertSame(1, $this->rpc('initialize')['result']['capabilities']['experimental']['onetrace/store']['version']);
        // Действия — только если владелец перечислил их в onetrace.store_mcp.tools.
        self::assertSame(['store_start_verification', 'store_verify', 'store_get_customer', 'store_get_order'], array_column($this->rpc('tools/list')['result']['tools'], 'name'));

        $this->postJson('/onetrace/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    public function testConfirmsACustomerByCodeAndAnswersWithTheToken(): void
    {
        StoreTools::$codes = [];
        $this->rpc('tools/call', ['name' => 'store_start_verification', 'arguments' => ['contact' => 'anna@example.com']]);
        $verified = $this->rpc('tools/call', ['name' => 'store_verify', 'arguments' => ['contact' => 'anna@example.com', 'code' => StoreTools::$codes[0]]])['result']['structuredContent'];

        $order = $this->rpc('tools/call', ['name' => 'store_get_order', 'arguments' => ['order' => '1001'], '_meta' => ['onetrace/customer_token' => $verified['customer_token']]]);

        self::assertSame(['order' => '1001', 'status' => 'shipped'], $order['result']['structuredContent']);
    }

    public function testAnnouncesVersionTwoWithTheOrderToolsAndOffersOrdersOnlyWhenAllowed(): void
    {
        $this->app['config']->set('onetrace.store_mcp.handlers', OrderTools::class);

        self::assertSame(2, $this->rpc('initialize')['result']['capabilities']['experimental']['onetrace/store']['version']);
        // Без разрешения store_create_order — только расчёт, и гостевые коды не нужны.
        self::assertSame(['store_get_customer', 'store_quote_order'], array_column($this->rpc('tools/list')['result']['tools'], 'name'));

        $this->app['config']->set('onetrace.store_mcp.tools', ['store_start_verification', 'store_verify', 'store_get_customer', 'store_quote_order', 'store_create_order']);
        self::assertSame(['store_start_verification', 'store_verify', 'store_get_customer', 'store_quote_order', 'store_create_order'], array_column($this->rpc('tools/list')['result']['tools'], 'name'));
    }

    public function testPlacesAGuestOrderFromAnAiAssistantOncePerIdempotencyKey(): void
    {
        OrderTools::$codes = [];
        OrderTools::$orders = [];
        $this->app['config']->set('onetrace.store_mcp.handlers', OrderTools::class);
        $this->app['config']->set('onetrace.store_mcp.tools', ['store_start_verification', 'store_verify', 'store_get_customer', 'store_quote_order', 'store_create_order']);

        $quote = $this->callTool('store_quote_order', ['items' => [['product_id' => 'sku-1', 'quantity' => 1], ['product_id' => 'sku-1', 'quantity' => '1']], 'shipping' => ['country' => 'de']]);
        self::assertSame([['product_id' => 'sku-1', 'quantity' => 2]], $quote['items']);
        self::assertSame(['country' => 'DE'], $quote['shipping']);

        // Код для гостевого заказа уходит на адрес без аккаунта; токен гостя проходит store_get_customer.
        $this->callTool('store_start_verification', ['contact' => 'New@Example.com', 'purpose' => 'order']);
        self::assertCount(1, OrderTools::$codes);
        $token = $this->callTool('store_verify', ['contact' => 'new@example.com', 'code' => OrderTools::$codes[0]])['customer_token'];
        self::assertSame(['id' => null, 'email' => 'new@example.com'], $this->callTool('store_get_customer', [], ['onetrace/customer_token' => $token]));

        $order = ['items' => [['product_id' => 'sku-1', 'quantity' => 2]], 'shipping' => ['first_name' => 'Anna', 'last_name' => 'Berg', 'country' => 'DE', 'city' => 'Berlin', 'address' => 'Hauptstr. 1'], 'shipping_method' => 'dhl'];
        $meta = ['onetrace/customer_token' => $token, 'onetrace/source' => 'assistant', 'onetrace/assistant' => 'claude', 'onetrace/idempotency_key' => 'order-1'];

        self::assertSame('not_allowed', $this->callTool('store_create_order', $order, $meta)['error']);

        $created = $this->callTool('store_create_order', $order, $meta + ['onetrace/confirmed' => true]);
        self::assertSame(['order' => '1001', 'status' => 'pending', 'total' => ['amount' => 50, 'currency' => 'EUR'], 'payment_status' => 'pending', 'payment_url' => 'https://shop.example.com/pay/1001'], $created);
        self::assertSame([['email' => 'new@example.com', 'source' => 'assistant', 'assistant' => 'claude', 'shipping' => $order['shipping']]], OrderTools::$orders);

        // Повтор с тем же ключом — тот же заказ из кеша, метод не вызывается второй раз.
        self::assertSame($created, $this->callTool('store_create_order', $order, $meta + ['onetrace/confirmed' => true]));
        self::assertCount(1, OrderTools::$orders);

        self::assertSame('1002', $this->callTool('store_create_order', $order, ['onetrace/idempotency_key' => 'order-2', 'onetrace/confirmed' => true] + $meta)['order']);
    }

    public function testRunsTheActionAgainWhenKeepingResultsIsOff(): void
    {
        OrderTools::$orders = [];
        $this->app['config']->set('onetrace.store_mcp.handlers', OrderTools::class);
        $this->app['config']->set('onetrace.store_mcp.tools', ['store_start_verification', 'store_verify', 'store_quote_order', 'store_create_order']);
        $this->app['config']->set('onetrace.store_mcp.idempotency', false);
        $token = $this->app->make(StoreMcp::class)->tokens()->issueGuest('+491701234567');

        $order = ['items' => [['product_id' => 'sku-1', 'quantity' => 1]], 'shipping' => ['first_name' => 'Anna', 'last_name' => 'Berg', 'country' => 'DE', 'city' => 'Berlin', 'address' => 'Hauptstr. 1'], 'shipping_method' => 'dhl'];
        $meta = ['onetrace/customer_token' => $token, 'onetrace/confirmed' => true, 'onetrace/idempotency_key' => 'order-1'];
        $this->callTool('store_create_order', $order, $meta);
        $this->callTool('store_create_order', $order, $meta);

        self::assertCount(2, OrderTools::$orders);
        self::assertSame([null, 'chat', null], [OrderTools::$orders[0]['email'], OrderTools::$orders[0]['source'], OrderTools::$orders[0]['assistant']]);
    }

    /**
     * The structured result of a tools/call.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function callTool(string $tool, array $arguments, array $meta = []): array
    {
        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments] + ($meta === [] ? [] : ['_meta' => $meta]))['result']['structuredContent'];
    }

    public function testGivesTheSignedInCustomerAChatTokenThroughTheTracker(): void
    {
        $this->app['config']->set('onetrace.write_key', 'cdp_wk_test');
        $user = User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        $this->actingAs($user);

        $html = Blade::render('@onetrace');
        self::assertMatchesRegularExpression('/cdp\.chatCustomer\("v1\.[^"]+"\);/', $html);

        preg_match('/cdp\.chatCustomer\("([^"]+)"\)/', $html, $match);
        self::assertSame((string) $user->getKey(), $this->app->make(StoreMcp::class)->tokens()->verify($match[1]));
    }
}
