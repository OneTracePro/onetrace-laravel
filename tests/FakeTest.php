<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use OneTrace\Laravel\Facades\OneTrace;
use PHPUnit\Framework\AssertionFailedError;

final class FakeTest extends TestCase
{
    public function testRecordsInsteadOfSending(): void
    {
        $fake = OneTrace::fake();

        OneTrace::track('order_completed', ['amount' => 9980], ['userId' => '42']);
        OneTrace::identify('42', ['plan' => 'pro']);
        OneTrace::page('Home', [], ['anonymousId' => 'a-1']);

        $fake->assertTracked('order_completed');
        $fake->assertTracked('order_completed', static fn (array $m): bool => $m['properties']['amount'] === 9980);
        $fake->assertTrackedTimes('order_completed', 1);
        $fake->assertNotTracked('refund');
        $fake->assertIdentified('42');
        $fake->assertPageViewed(static fn (array $m): bool => $m['name'] === 'Home');
        self::assertSame([], $this->transport->requests);
    }

    public function testFailingAssertions(): void
    {
        $fake = OneTrace::fake();
        OneTrace::track('a', [], ['userId' => '1']);

        $this->expectException(AssertionFailedError::class);

        $fake->assertNothingSent();
    }

    public function testClientCallsAreRecordedToo(): void
    {
        $fake = OneTrace::fake()->respondWith(['id' => 5, 'name' => 'VIP']);

        self::assertSame(['id' => 5, 'name' => 'VIP'], OneTrace::segments()->create(['name' => 'VIP', 'type' => 'static']));
        self::assertSame('https://onetrace.test/api/v1/segments', $fake->requests()[0]->getUrl());
        self::assertSame([], $this->transport->requests);
    }
}
