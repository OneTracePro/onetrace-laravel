<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use OneTrace\Client;

/**
 * Creates, updates and deletes catalog products, up to 1000 per request. Repeating it is harmless.
 */
class SyncProducts implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const CHUNK = 1000;

    public int $tries = 5;

    /**
     * @param list<array<string, mixed>> $upserts
     * @param list<string>               $deletes
     */
    public function __construct(public array $upserts, public array $deletes = [])
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
        foreach (array_chunk($this->upserts, self::CHUNK) as $chunk) {
            $client->products()->upsert($chunk);
        }

        foreach (array_chunk($this->deletes, self::CHUNK) as $chunk) {
            $client->products()->delete($chunk);
        }
    }
}
