<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use OneTrace\Laravel\OneTrace;

/**
 * Octane serves every request from a clone of the booted application (the sandbox) inside a CLI process: services
 * scoped to the request live in the clone, Container::getInstance() points to it, and runningInConsole() is true
 * unless APP_RUNNING_IN_CONSOLE says otherwise.
 */
final class OctaneTest extends TestCase
{
    public function testEventsOfASandboxRequestAreFlushedWhenItTerminates(): void
    {
        $base = $this->app;
        $sandbox = clone $base;
        $sandbox['env'] = 'production'; // not a unit test run, as under Octane
        // What Laravel\Octane\CurrentApplication::set() does for every request.
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        Container::setInstance($sandbox);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($sandbox);

        try {
            $sandbox->instance('request', Request::create('/checkout', 'POST', [], [], [], [
                'HTTP_COOKIE' => 'cdp_aid=visitor-1',
                'REMOTE_ADDR' => '203.0.113.7',
            ]));

            $sandbox->make(OneTrace::class)->track('order_completed', [], ['userId' => '42']);
            $sandbox->terminate();

            $messages = $this->sentMessages();
            self::assertCount(1, $messages, 'The batch is sent when the sandbox terminates.');
            self::assertSame('visitor-1', $messages[0]['anonymousId']);
            self::assertSame('203.0.113.7', $messages[0]['context']['ip']);
        } finally {
            Container::setInstance($base);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($base);
        }
    }
}
