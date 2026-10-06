<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use OneTrace\Laravel\Testing\OneTraceFake;

/**
 * @method static void track(string $event, array<string, mixed> $properties = [], array<string, mixed> $message = [])
 * @method static void identify(\Illuminate\Contracts\Auth\Authenticatable|string|int|null $user = null, array<string, mixed> $traits = [], array<string, mixed> $message = [])
 * @method static void page(?string $name = null, array<string, mixed> $properties = [], array<string, mixed> $message = [])
 * @method static void alias(string|int $previousId, string|int|null $userId = null)
 * @method static void syncProducts(iterable<mixed> $products)
 * @method static void deleteProducts(iterable<mixed> $ids)
 * @method static void flush()
 * @method static int pending()
 * @method static string|null anonymousId()
 * @method static bool enabled()
 * @method static \OneTrace\Client client()
 * @method static \OneTrace\Resource\Events events()
 * @method static \OneTrace\Resource\Profiles profiles()
 * @method static \OneTrace\Resource\Products products()
 * @method static \OneTrace\Resource\Catalog catalog()
 * @method static \OneTrace\Resource\Recommendations recommendations()
 * @method static \OneTrace\Resource\Widgets widgets()
 * @method static \OneTrace\Resource\Push push()
 * @method static \OneTrace\Resource\Segments segments()
 * @method static \OneTrace\Resource\Journeys journeys()
 * @method static \OneTrace\Resource\Campaigns campaigns()
 *
 * @see \OneTrace\Laravel\OneTrace
 */
class OneTrace extends Facade
{
    /**
     * Replace the integration with a fake that records instead of sending.
     */
    public static function fake(): OneTraceFake
    {
        $app = static::getFacadeApplication();

        if ($app === null) {
            throw new \RuntimeException('The facade application is not set.');
        }

        /** @var \Illuminate\Contracts\Foundation\Application $app */
        $fake = new OneTraceFake($app);
        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \OneTrace\Laravel\OneTrace::class;
    }
}
