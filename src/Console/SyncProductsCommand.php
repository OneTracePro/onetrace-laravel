<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use OneTrace\Client;
use OneTrace\Laravel\Contracts\OneTraceProduct;

/**
 * Uploads the whole catalog: php artisan onetrace:sync-products "App\Models\Product".
 */
class SyncProductsCommand extends Command
{
    protected $signature = 'onetrace:sync-products
        {model : Eloquent model class that implements OneTrace\Laravel\Contracts\OneTraceProduct}
        {--chunk=1000 : products per request (up to 1000)}';

    protected $description = 'Upload all products of a model to the OneTrace.pro catalog';

    public function handle(Client $client): int
    {
        $class = $this->argument('model');
        $class = \is_string($class) ? $class : '';

        if (!class_exists($class) || !is_subclass_of($class, Model::class) || !is_subclass_of($class, OneTraceProduct::class)) {
            $this->error(sprintf('%s must be an Eloquent model implementing %s.', $class, OneTraceProduct::class));

            return self::FAILURE;
        }

        $chunkOption = $this->option('chunk');
        $size = max(1, min(1000, is_numeric($chunkOption) ? (int) $chunkOption : 1000));
        $total = 0;
        $chunk = [];

        foreach ($class::query()->lazyById($size) as $model) {
            /** @var Model&OneTraceProduct $model */
            if (method_exists($model, 'shouldSyncWithOneTrace') && !$model->shouldSyncWithOneTrace()) {
                continue;
            }

            $chunk[] = $model->toOneTraceProduct();

            if (\count($chunk) >= $size) {
                $total += $this->send($client, $chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $total += $this->send($client, $chunk);
        }

        $this->info(sprintf('Synced %d products.', $total));

        return self::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $chunk
     */
    private function send(Client $client, array $chunk): int
    {
        $client->products()->upsert($chunk);
        $this->line(sprintf('  … %d', \count($chunk)));

        return \count($chunk);
    }
}
