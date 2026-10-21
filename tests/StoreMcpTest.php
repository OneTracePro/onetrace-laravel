<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use OneTrace\Laravel\StoreMcp\StoreMcp;
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
