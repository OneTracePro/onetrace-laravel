<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use OneTrace\Client;

/**
 * Sends a batch of prepared events. Messages keep their messageId, so a retried job is not counted twice.
 */
class SendEvents implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /**
     * @param list<array<string, mixed>> $messages
     */
    public function __construct(public array $messages)
    {
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(Client $client): void
    {
        $client->events()->batch($this->messages);
    }
}
