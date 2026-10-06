<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use OneTrace\Laravel\Facades\OneTrace;
use OneTrace\Laravel\Jobs\SendEvents;
use OneTrace\Laravel\Jobs\SyncProducts;

final class SendModesTest extends TestCase
{
    public function testSyncSendsImmediately(): void
    {
        config(['onetrace.send' => 'sync']);

        OneTrace::track('a', [], ['userId' => '1']);
        self::assertCount(1, $this->transport->requests);

        OneTrace::syncProducts([['id' => 'SKU-1', 'name' => 'Sneakers']]);
        self::assertCount(2, $this->transport->requests);
        self::assertSame('https://cdp.example.com/api/v1/products', $this->transport->requests[1]->getUrl());
        self::assertSame('Bearer cdp_sk_test', $this->transport->requests[1]->getHeader('Authorization'));
    }

    public function testQueueDispatchesOneJobPerFlush(): void
    {
        Queue::fake();
        config(['onetrace.send' => 'queue', 'onetrace.queue.connection' => 'redis', 'onetrace.queue.name' => 'analytics']);

        OneTrace::track('a', [], ['userId' => '1']);
        OneTrace::track('b', [], ['userId' => '1']);
        OneTrace::deleteProducts(['SKU-9']);
        OneTrace::flush();

        self::assertSame([], $this->transport->requests);
        Queue::assertPushed(SendEvents::class, static function (SendEvents $job): bool {
            return \count($job->messages) === 2 && $job->connection === 'redis' && $job->queue === 'analytics'
                && $job->messages[0]['messageId'] !== '' && $job->messages[1]['event'] === 'b';
        });
        Queue::assertPushed(SyncProducts::class, static fn (SyncProducts $job): bool => $job->deletes === ['SKU-9']);
    }

    public function testJobsSendThroughTheClient(): void
    {
        $messages = [['type' => 'track', 'userId' => '1', 'event' => 'a', 'messageId' => 'm-1']];

        $this->app->call([new SendEvents($messages), 'handle']);
        $this->app->call([new SyncProducts(array_map(static fn (int $i): array => ['id' => (string) $i, 'name' => 'P' . $i], range(1, 1500)), ['x']), 'handle']);

        self::assertSame('m-1', $this->sentMessages()[0]['messageId']);
        $urls = array_map(static fn ($r) => $r->getMethod() . ' ' . $r->getUrl(), $this->transport->requests);
        self::assertSame([
            'POST https://cdp.example.com/api/v1/batch',
            'POST https://cdp.example.com/api/v1/products',
            'POST https://cdp.example.com/api/v1/products',
            'DELETE https://cdp.example.com/api/v1/products',
        ], $urls);
    }

    public function testQueueWorkersFlushAfterEachJob(): void
    {
        OneTrace::track('from_job', [], ['userId' => '1']);
        self::assertSame([], $this->transport->requests);

        $this->app->make('events')->dispatch(new JobProcessed('sync', \Mockery::mock(\Illuminate\Contracts\Queue\Job::class)));

        self::assertSame('from_job', $this->sentMessages()[0]['event']);
    }

    public function testLaterChangesOfAProductWin(): void
    {
        OneTrace::syncProducts([['id' => 'SKU-1', 'name' => 'Old']]);
        OneTrace::syncProducts([['id' => 'SKU-1', 'name' => 'New']]);
        OneTrace::deleteProducts(['SKU-2']);
        OneTrace::syncProducts([['id' => 'SKU-2', 'name' => 'Back']]);
        OneTrace::flush();

        self::assertCount(1, $this->transport->requests);
        self::assertSame(['items' => [['id' => 'SKU-1', 'name' => 'New'], ['id' => 'SKU-2', 'name' => 'Back']]], json_decode((string) $this->transport->requests[0]->getBody(), true));
    }
}
