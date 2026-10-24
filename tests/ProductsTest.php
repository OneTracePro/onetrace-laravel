<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use OneTrace\Laravel\Facades\OneTrace;
use OneTrace\Laravel\Tests\Fixtures\Product;

final class ProductsTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('products', static function ($table): void {
            $table->id();
            $table->string('sku');
            $table->string('name');
            $table->decimal('price', 10, 2);
            $table->text('description')->nullable();
            $table->boolean('hidden_from_catalog')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function testSavingAndDeletingAModelSyncsTheCatalog(): void
    {
        $fake = OneTrace::fake();

        $product = Product::create(['sku' => 'SKU-1', 'name' => 'Sneakers', 'price' => 4990]);
        $fake->assertProductSynced(static fn (array $data): bool => $data === ['id' => 'SKU-1', 'name' => 'Sneakers', 'price' => 4990.0]);

        $product->delete();
        $fake->assertProductDeleted('SKU-1');

        $product->restore();
        $fake->assertProductSynced('SKU-1');
    }

    public function testSendsTranslationsOfProductsForOtherLanguagesOfTheStore(): void
    {
        $fake = OneTrace::fake();

        OneTrace::syncProducts([['id' => 'SKU-1', 'name' => 'Кроссовки', 'translations' => ['en' => ['name' => 'Sneakers', 'url' => 'https://shop.example/en/sku-1']]]]);

        $fake->assertProductSynced(static fn (array $data): bool => ($data['translations']['en']['name'] ?? null) === 'Sneakers');
    }

    public function testSendsTheDescriptionAsTextForTheAiAnswers(): void
    {
        $fake = OneTrace::fake();

        Product::create(['sku' => 'SKU-1', 'name' => 'Sneakers', 'price' => 4990, 'description' => '<p>Leather&nbsp;upper.</p><ul><li>Size 42</li></ul>']);
        OneTrace::syncProducts([['id' => 'SKU-2', 'name' => 'Boots', 'description' => '  ', 'translations' => ['en' => ['name' => 'Boots', 'description' => '<b>' . str_repeat('a', 6000) . '</b>']]]]);

        $fake->assertProductSynced(static fn (array $data): bool => $data['id'] === 'SKU-1' && $data['description'] === "Leather upper.\n• Size 42");
        $fake->assertProductSynced(static fn (array $data): bool => $data['id'] === 'SKU-2' && !\array_key_exists('description', $data)
            && $data['translations']['en']['description'] === str_repeat('a', 5000));
    }

    public function testTheCommandSendsDescriptionsAsText(): void
    {
        $fake = OneTrace::fake();
        Product::create(['sku' => 'SKU-1', 'name' => 'Sneakers', 'price' => 1, 'description' => '<p>Leather upper.</p>']);

        $this->artisan('onetrace:sync-products', ['model' => Product::class])->assertSuccessful();

        $items = json_decode((string) $this->transport->requests[0]->getBody(), true)['items'];
        self::assertSame('Leather upper.', $items[0]['description']);
        unset($fake);
    }

    public function testRendersTheAskAiElementOfTheProductPage(): void
    {
        $html = Blade::render('<x-onetrace::ask :product="$id" class="mt-6" />', ['id' => 'SKU-1"><script>']);

        self::assertStringContainsString('<div data-cdp-ask data-product-id="SKU-1&quot;&gt;&lt;script&gt;" class="mt-6"></div>', $html);
    }

    public function testHiddenModelsAreNotSynced(): void
    {
        $fake = OneTrace::fake();

        Product::create(['sku' => 'SKU-2', 'name' => 'Draft', 'price' => 1, 'hidden_from_catalog' => true]);

        $fake->assertNothingSent();
    }

    public function testChangesGoInOneRequestAfterTheResponse(): void
    {
        Product::create(['sku' => 'SKU-1', 'name' => 'A', 'price' => 1]);
        Product::create(['sku' => 'SKU-2', 'name' => 'B', 'price' => 2])->delete();

        $this->app->terminate();

        $requests = array_map(static fn ($r) => [$r->getMethod(), json_decode((string) $r->getBody(), true)], $this->transport->requests);
        self::assertSame([
            ['POST', ['items' => [['id' => 'SKU-1', 'name' => 'A', 'price' => 1.0]]]],
            ['DELETE', ['ids' => ['SKU-2']]],
        ], $requests);
    }

    public function testTheCommandUploadsTheWholeCatalogInChunks(): void
    {
        $fake = OneTrace::fake(); // keeps model events quiet; the command talks to the client directly

        foreach (range(1, 5) as $i) {
            Product::create(['sku' => 'SKU-' . $i, 'name' => 'P' . $i, 'price' => $i, 'hidden_from_catalog' => $i === 5]);
        }

        $this->artisan('onetrace:sync-products', ['model' => Product::class, '--chunk' => 2])
            ->expectsOutputToContain('Synced 4 products.')
            ->assertSuccessful();

        $bodies = array_map(static fn ($r) => array_column(json_decode((string) $r->getBody(), true)['items'], 'id'), $this->transport->requests);
        self::assertSame([['SKU-1', 'SKU-2'], ['SKU-3', 'SKU-4']], $bodies);
        unset($fake);
    }

    public function testTheCommandRejectsOtherClasses(): void
    {
        $this->artisan('onetrace:sync-products', ['model' => \stdClass::class])->assertFailed();
    }
}
