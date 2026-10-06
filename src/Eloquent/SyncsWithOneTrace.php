<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Eloquent;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use OneTrace\Laravel\OneTrace;

/**
 * Keeps a product model in the OneTrace.pro catalog: saved models are created or updated, deleted ones removed
 * (soft-deleted too; restored models come back). Changes are sent like events — after the response, via the
 * queue or immediately — in batches of up to 1000.
 *
 *     class Product extends Model implements OneTraceProduct
 *     {
 *         use SyncsWithOneTrace;
 *
 *         public function toOneTraceProduct(): array
 *         {
 *             return ['id' => $this->sku, 'name' => $this->title, 'price' => $this->price, 'url' => route('products.show', $this)];
 *         }
 *     }
 *
 * @mixin Model
 */
trait SyncsWithOneTrace
{
    public static function bootSyncsWithOneTrace(): void
    {
        static::saved(static function (Model $model): void {
            /** @var Model&\OneTrace\Laravel\Contracts\OneTraceProduct $model */
            if ($model->shouldSyncWithOneTrace()) {
                Container::getInstance()->make(OneTrace::class)->syncProducts([$model]);
            }
        });

        static::deleted(static function (Model $model): void {
            /** @var Model&\OneTrace\Laravel\Contracts\OneTraceProduct $model */
            Container::getInstance()->make(OneTrace::class)->deleteProducts([$model->oneTraceProductId()]);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(static function (Model $model): void {
                /** @var Model&\OneTrace\Laravel\Contracts\OneTraceProduct $model */
                if ($model->shouldSyncWithOneTrace()) {
                    Container::getInstance()->make(OneTrace::class)->syncProducts([$model]);
                }
            });
        }
    }

    public function oneTraceProductId(): string
    {
        $id = $this->toOneTraceProduct()['id'] ?? $this->getKey();

        return \is_scalar($id) ? (string) $id : '';
    }

    /**
     * Override to keep some models out of the catalog (drafts, hidden products).
     */
    public function shouldSyncWithOneTrace(): bool
    {
        return true;
    }
}
