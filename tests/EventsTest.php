<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use OneTrace\Laravel\Facades\OneTrace;
use OneTrace\Laravel\Tests\Fixtures\Customer;
use OneTrace\Laravel\Tests\Fixtures\User;

final class EventsTest extends TestCase
{
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

    protected function defineRoutes($router): void
    {
        $router->post('/checkout', static function () {
            OneTrace::track('order_completed', ['order_id' => 'A-1001', 'amount' => 9980], ['messageId' => 'order-A-1001']);
            OneTrace::page('Checkout');

            return response('ok');
        });
    }

    public function testEventsAreSentInOneBatchAfterTheResponse(): void
    {
        $user = User::create(['name' => 'Anna', 'email' => 'anna@example.com']);

        $this->actingAs($user)
            ->withHeader('Cookie', 'cdp_aid=7d1c0d9e-1111-4222-8333-944455556666; other=1')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->post('/checkout')
            ->assertOk();

        // The test client terminates the application after the response, like the HTTP kernel does.
        self::assertCount(1, $this->transport->requests, 'One batch for the whole request.');
        [$track, $page] = $this->sentMessages();

        self::assertSame('track', $track['type']);
        self::assertSame('order_completed', $track['event']);
        self::assertSame('order-A-1001', $track['messageId']);
        self::assertSame((string) $user->id, $track['userId']);
        self::assertSame('7d1c0d9e-1111-4222-8333-944455556666', $track['anonymousId']);
        self::assertSame('203.0.113.5', $track['context']['ip']);
        self::assertSame(['order_id' => 'A-1001', 'amount' => 9980], $track['properties']);
        self::assertSame('Checkout', $page['name']);
        self::assertSame('http://localhost/checkout', $page['properties']['url']);
        self::assertSame('Bearer cdp_wk_test', $this->transport->requests[0]->getHeader('Authorization'));
    }

    public function testIdentifySendsTheUserTraits(): void
    {
        $user = User::create(['name' => 'Anna', 'email' => 'anna@example.com']);
        $customer = Customer::create(['email' => 'boris@example.com']);

        OneTrace::identify($user, ['plan' => 'free']);
        OneTrace::identify($customer);
        OneTrace::identify(null, ['email' => 'lead@example.com'], ['anonymousId' => 'a-1']);
        OneTrace::flush();

        [$first, $second, $third] = $this->sentMessages();
        self::assertSame(['email' => 'anna@example.com', 'name' => 'Anna', 'plan' => 'free'], $first['traits']);
        self::assertSame((string) $customer->id, $second['userId']);
        self::assertSame(['email' => 'boris@example.com', 'plan' => 'pro'], $second['traits']);
        self::assertArrayNotHasKey('userId', $third);
        self::assertSame('a-1', $third['anonymousId']);
    }

    public function testAliasUsesTheSignedInUser(): void
    {
        $user = User::create(['email' => 'anna@example.com']);
        $this->actingAs($user);

        OneTrace::alias('old-42');
        OneTrace::flush();

        self::assertSame(['old-42', (string) $user->id], [$this->sentMessages()[0]['previousId'], $this->sentMessages()[0]['userId']]);
    }

    public function testInvalidEventsFailAtTheCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OneTrace::track('order_completed'); // no user, no cookie
    }

    public function testNothingIsSentWhenDisabledOrWithoutKeys(): void
    {
        config(['onetrace.enabled' => false]);
        OneTrace::track('x', [], ['userId' => '1']);

        config(['onetrace.enabled' => true, 'onetrace.write_key' => null, 'onetrace.secret_key' => null]);
        OneTrace::track('x', [], ['userId' => '1']);
        OneTrace::flush();

        self::assertSame([], $this->transport->requests);
    }

    public function testErrorsAfterTheResponseAreReportedNotThrown(): void
    {
        $this->transport->respond(500, ['message' => 'Server Error']);
        $reported = [];
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->reportable(static function (\Throwable $e) use (&$reported): void {
            $reported[] = $e;
        });

        OneTrace::track('x', [], ['userId' => '1']);
        $this->app->terminate();

        self::assertCount(1, $reported);
        self::assertInstanceOf(\OneTrace\Exception\ServerException::class, $reported[0]);
    }

    public function testTheClientIsAvailableThroughTheFacade(): void
    {
        $this->transport->respond(200, ['data' => [['id' => 1]], 'next_cursor' => null]);

        self::assertCount(1, OneTrace::segments()->list());
        self::assertSame('https://cdp.example.com/api/v1/segments', $this->transport->requests[0]->getUrl());
        self::assertSame('Bearer cdp_sk_test', $this->transport->requests[0]->getHeader('Authorization'));
    }
}
