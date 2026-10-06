<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Testing;

use Illuminate\Contracts\Foundation\Application;
use OneTrace\Client;
use OneTrace\Laravel\OneTrace;
use PHPUnit\Framework\Assert;

/**
 * OneTrace::fake(): nothing is sent; events and product changes are recorded for assertions, and client() talks to
 * a RecordingTransport (see requests()).
 */
class OneTraceFake extends OneTrace
{
    /** @var list<array<string, mixed>> */
    protected array $sent = [];

    /** @var array<string, array<string, mixed>> */
    protected array $syncedProducts = [];

    /** @var list<string> */
    protected array $deletedProducts = [];

    protected RecordingTransport $transport;

    protected ?Client $fakeClient = null;

    public function __construct(Application $app)
    {
        parent::__construct($app);
        $this->transport = new RecordingTransport();
    }

    public function enabled(): bool
    {
        return true;
    }

    public function client(): Client
    {
        return $this->fakeClient ??= new Client('https://onetrace.test', [
            'write_key' => 'cdp_wk_fake',
            'secret_key' => 'cdp_sk_fake',
            'transport' => $this->transport,
            'max_retries' => 0,
        ]);
    }

    /**
     * Requests sent through client() (OneTrace::segments()->…), for assertions on management calls.
     *
     * @return list<\OneTrace\Http\Request>
     */
    public function requests(): array
    {
        return $this->transport->requests;
    }

    /**
     * Queue a response for the next client() request.
     *
     * @param array<string, mixed>|list<mixed> $json
     */
    public function respondWith(array $json, int $status = 200): static
    {
        $this->transport->respond($status, $json);

        return $this;
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $callback receives the message (properties, userId…)
     */
    public function assertTracked(string $event, ?callable $callback = null): void
    {
        Assert::assertNotEmpty($this->messages('track', $event, $callback), sprintf('The event "%s" was not tracked.', $event));
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $callback
     */
    public function assertNotTracked(string $event, ?callable $callback = null): void
    {
        Assert::assertEmpty($this->messages('track', $event, $callback), sprintf('The event "%s" was tracked.', $event));
    }

    public function assertTrackedTimes(string $event, int $times): void
    {
        Assert::assertCount($times, $this->messages('track', $event), sprintf('The event "%s" was not tracked %d times.', $event, $times));
    }

    /**
     * @param (callable(array<string, mixed>): bool)|string|int|null $user a user id or a callback for the message
     */
    public function assertIdentified(callable|string|int|null $user = null): void
    {
        $callback = \is_callable($user) ? $user : ($user === null ? null : static function (array $message) use ($user): bool {
            return ($message['userId'] ?? null) === (string) $user;
        });

        Assert::assertNotEmpty($this->messages('identify', null, $callback), 'The expected identify was not sent.');
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $callback
     */
    public function assertPageViewed(?callable $callback = null): void
    {
        Assert::assertNotEmpty($this->messages('page', null, $callback), 'No page view was sent.');
    }

    /**
     * @param (callable(array<string, mixed>): bool)|string|null $product a product id or a callback for the product data
     */
    public function assertProductSynced(callable|string|null $product = null): void
    {
        $this->flush();
        $matches = array_filter($this->syncedProducts, static function (array $data, string $id) use ($product): bool {
            return $product === null || (\is_callable($product) ? (bool) $product($data) : $id === $product);
        }, ARRAY_FILTER_USE_BOTH);

        Assert::assertNotEmpty($matches, 'The expected product was not synced.');
    }

    public function assertProductDeleted(string $id): void
    {
        $this->flush();
        Assert::assertContains($id, $this->deletedProducts, sprintf('The product "%s" was not deleted.', $id));
    }

    public function assertNothingSent(): void
    {
        $this->flush();
        Assert::assertSame([], $this->sent, 'Events were sent.');
        Assert::assertSame([], $this->syncedProducts, 'Products were synced.');
        Assert::assertSame([], $this->deletedProducts, 'Products were deleted.');
    }

    /**
     * Everything sent so far: messages as the API would receive them.
     *
     * @return list<array<string, mixed>>
     */
    public function sent(): array
    {
        $this->flush();

        return $this->sent;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    protected function deliverEvents(array $events): void
    {
        array_push($this->sent, ...$events);
    }

    /**
     * @param list<array<string, mixed>> $upserts
     * @param list<string>               $deletes
     */
    protected function deliverProducts(array $upserts, array $deletes): void
    {
        foreach ($upserts as $product) {
            $this->syncedProducts[(string) $product['id']] = $product;
        }

        array_push($this->deletedProducts, ...$deletes);
    }

    /**
     * @param (callable(array<string, mixed>): bool)|null $callback
     *
     * @return list<array<string, mixed>>
     */
    protected function messages(string $type, ?string $event = null, ?callable $callback = null): array
    {
        return array_values(array_filter($this->sent(), static function (array $message) use ($type, $event, $callback): bool {
            return $message['type'] === $type
                && ($event === null || ($message['event'] ?? null) === $event)
                && ($callback === null || (bool) $callback($message));
        }));
    }
}
