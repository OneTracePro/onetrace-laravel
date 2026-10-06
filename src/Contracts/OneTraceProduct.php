<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Contracts;

/**
 * A model that is a product of the catalog. The SyncsWithOneTrace trait implements oneTraceProductId() and keeps
 * the catalog in sync on save and delete.
 */
interface OneTraceProduct
{
    /**
     * The product as the API expects it: id and name are required; url, image, price, old_price, currency,
     * available, category_ids, brand, params are optional.
     *
     * @return array<string, mixed>
     */
    public function toOneTraceProduct(): array;

    /**
     * The id used in events (product_id) and in the catalog.
     */
    public function oneTraceProductId(): string;
}
