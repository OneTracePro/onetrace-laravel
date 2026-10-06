<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OneTrace\Laravel\Contracts\OneTraceProduct;
use OneTrace\Laravel\Eloquent\SyncsWithOneTrace;

class Product extends Model implements OneTraceProduct
{
    use SoftDeletes;
    use SyncsWithOneTrace;

    protected $guarded = [];

    public function toOneTraceProduct(): array
    {
        return ['id' => $this->sku, 'name' => $this->name, 'price' => (float) $this->price];
    }

    public function shouldSyncWithOneTrace(): bool
    {
        return !$this->hidden_from_catalog;
    }
}
