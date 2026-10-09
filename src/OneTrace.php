<?php

declare(strict_types=1);

namespace OneTrace\Laravel;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use OneTrace\Client;
use OneTrace\Laravel\Contracts\HasOneTraceTraits;
use OneTrace\Laravel\Contracts\OneTraceProduct;
use OneTrace\Laravel\Jobs\SendEvents;
use OneTrace\Laravel\Jobs\SyncProducts;
use OneTrace\Resource\Events;

/**
 * Entry point behind the OneTrace facade.
 *
 * Events and product changes are collected and sent according to config("onetrace.send"): after the response,
 * via the queue or immediately. The visitor id from the tracker cookie and the signed-in user are added to
 * events automatically. Every other API call goes straight to the OneTrace\Client: OneTrace::segments()->list().
 *
 * @mixin Client
 */
class OneTrace
{
    public const SEND_AFTER_RESPONSE = 'after_response';
    public const SEND_QUEUE = 'queue';
    public const SEND_SYNC = 'sync';

    /** @var list<array<string, mixed>> */
    protected array $events = [];

    /** @var array<string, array<string, mixed>> products to create or update, by id */
    protected array $upserts = [];

    /** @var array<string, true> product ids to delete */
    protected array $deletes = [];

    /** Visitor id set with useAnonymousId() for the current request or job. */
    protected ?string $anonymousId = null;

    /** @var (\Closure(?Request): (string|null))|null set once in a service provider */
    protected static ?\Closure $anonymousIdResolver = null;

    public function __construct(protected Application $app)
    {
    }

    public function __destruct()
    {
        $this->flushQuietly();
    }

    /**
     * An action of the user or the visitor: OneTrace::track('order_completed', ['order_id' => 'A-1001', 'amount' => 9980]).
     *
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $message    other message fields: userId, anonymousId, messageId, timestamp, context
     */
    public function track(string $event, array $properties = [], array $message = []): void
    {
        $this->record('track', array_merge(['event' => $event, 'properties' => $properties], $message));
    }

    /**
     * Links the visitor to a user and updates profile traits. Without $user — the signed-in user; with traits only
     * (OneTrace::identify(null, ['email' => $email])) — the visitor from the tracker cookie, e.g. after a newsletter form.
     *
     * @param Authenticatable|string|int|null $user
     * @param array<string, mixed>            $traits added to the user's own traits (email, name or oneTraceTraits())
     * @param array<string, mixed>            $message
     */
    public function identify(Authenticatable|string|int|null $user = null, array $traits = [], array $message = []): void
    {
        $user ??= $this->currentUser();

        if ($user instanceof Authenticatable) {
            $traits = array_merge($this->traitsOf($user), $traits);
            $user = $user->getAuthIdentifier();
        }

        if ($user !== null && !\is_scalar($user)) {
            throw new \InvalidArgumentException('identify() takes a user model or a user id.');
        }

        $this->record('identify', array_merge(
            ($user === null || $user === '') ? [] : ['userId' => (string) $user],
            ['traits' => $traits],
            $message
        ));
    }

    /**
     * A page view tracked from the server.
     *
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $message
     */
    public function page(?string $name = null, array $properties = [], array $message = []): void
    {
        $request = $this->request();

        if ($request !== null) {
            $properties += array_filter(['url' => $request->fullUrl(), 'path' => '/' . ltrim($request->path(), '/'), 'referrer' => $request->headers->get('referer')]);
        }

        $this->record('page', array_merge(array_filter(['name' => $name]), ['properties' => $properties], $message));
    }

    /**
     * Merges a previous identifier into a user (by default the signed-in one).
     */
    public function alias(string|int $previousId, string|int|null $userId = null): void
    {
        $userId ??= $this->currentUser()?->getAuthIdentifier();

        if ($userId === null || !\is_scalar($userId)) {
            throw new \InvalidArgumentException('alias() needs a user id or a signed-in user.');
        }

        $this->record('alias', ['previousId' => (string) $previousId, 'userId' => (string) $userId]);
    }

    /**
     * Creates or updates products in the catalog: models implementing OneTraceProduct or arrays with "id".
     *
     * @param iterable<OneTraceProduct|array<string, mixed>> $products
     */
    public function syncProducts(iterable $products): void
    {
        if (!$this->enabled()) {
            return;
        }

        foreach ($products as $product) {
            $data = $product instanceof OneTraceProduct ? $product->toOneTraceProduct() : $product;
            $id = $data['id'] ?? null;

            if (!\is_scalar($id) || (string) $id === '') {
                throw new \InvalidArgumentException('A product needs an "id".');
            }

            $data['id'] = (string) $id;
            unset($this->deletes[$data['id']]);
            $this->upserts[$data['id']] = $data;
        }

        $this->sendNowIfSync();
    }

    /**
     * Product search for your results page: the visitor of the tracker cookie (the order by their interests) and the
     * application locale (names in the catalog translation) are added unless given; the first page records the
     * search event, from which the popular queries of the search box suggestions come.
     *
     * @param array<string, mixed> $params category, brand, price_min, price_max, sort, page, per_page, all, image_width,
     *                                     anonymousId, language — see GET /api/v1/search
     *
     * @return array<string, mixed> items, total, page, per_page, relaxed, personalized, facets
     */
    public function searchProducts(string $query, array $params = []): array
    {
        if (!$this->enabled()) {
            return ['items' => [], 'total' => 0, 'facets' => ['categories' => [], 'brands' => [], 'price' => ['min' => null, 'max' => null]]];
        }

        $params += array_filter(['anonymousId' => $this->anonymousId(), 'language' => $this->app->getLocale()]);
        $result = $this->client()->search()->products($query, $params);
        $query = trim($query);

        if ($query !== '' && (int) ($params['page'] ?? 1) === 1) {
            $this->track('search', array_filter([
                'query' => mb_substr($query, 0, 200),
                'results' => (int) ($result['total'] ?? 0),
                'request_id' => $result['request_id'] ?? null,
            ], static fn ($value): bool => $value !== null));
        }

        return $result;
    }

    /**
     * Deletes products from the catalog by id.
     *
     * @param iterable<string|int|OneTraceProduct> $ids
     */
    public function deleteProducts(iterable $ids): void
    {
        if (!$this->enabled()) {
            return;
        }

        foreach ($ids as $id) {
            $id = $id instanceof OneTraceProduct ? $id->oneTraceProductId() : (string) $id;
            unset($this->upserts[$id]);
            $this->deletes[$id] = true;
        }

        $this->sendNowIfSync();
    }

    /**
     * Sends everything collected so far (events and products). Called automatically after the response,
     * after each queued job and at the end of artisan commands; errors are thrown.
     */
    public function flush(): void
    {
        $events = $this->events;
        $upserts = array_values($this->upserts);
        $deletes = array_map('strval', array_keys($this->deletes));
        $this->events = $this->upserts = $this->deletes = [];

        if ($events !== []) {
            $this->deliverEvents($events);
        }

        if ($upserts !== [] || $deletes !== []) {
            $this->deliverProducts($upserts, $deletes);
        }
    }

    /**
     * flush() that reports errors to the exception handler instead of throwing: used after the response.
     */
    public function flushQuietly(): void
    {
        try {
            $this->flush();
        } catch (\Throwable $e) {
            if ($this->app->bound(ExceptionHandler::class)) {
                $this->app->make(ExceptionHandler::class)->report($e);
            }
        }
    }

    /**
     * Messages and product changes waiting to be sent.
     */
    public function pending(): int
    {
        return \count($this->events) + \count($this->upserts) + \count($this->deletes);
    }

    /**
     * The API client for everything else: profiles, segments, journeys, campaigns, recommendations…
     */
    public function client(): Client
    {
        return $this->app->make(Client::class);
    }

    /**
     * Proxies OneTrace::segments(), OneTrace::profiles() and other resources to the client.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $client = $this->client();

        if (!method_exists($client, $method)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
        }

        return $client->{$method}(...$arguments);
    }

    /**
     * Sets the visitor id for the rest of the current request or job: every following event — including the automatic
     * identify on login and registration — carries it, so the profile is merged with the visitor's history before
     * sign-in. Use it when the id does not come in the tracker cookie: from a mobile app, an SPA, a form field or a job
     * payload. Null goes back to the automatic sources.
     */
    public function useAnonymousId(?string $anonymousId): void
    {
        $this->anonymousId = self::validAnonymousId($anonymousId);

        if ($anonymousId !== null && $this->anonymousId === null) {
            throw new \InvalidArgumentException('An anonymous id is 1–100 characters: letters, digits, ".", "_", ":", "-".');
        }
    }

    /**
     * Your own way to find the visitor id, set once in a service provider:
     * OneTrace::resolveAnonymousIdUsing(fn (?Request $request) => $request?->input('anonymous_id')).
     * It is asked after useAnonymousId() and before the header and the tracker cookie. Null removes it.
     *
     * @param (callable(?Request): (string|null))|null $resolver
     */
    public static function resolveAnonymousIdUsing(?callable $resolver): void
    {
        static::$anonymousIdResolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /**
     * The visitor id added to events: set with useAnonymousId(), from your resolver, from the request header
     * config("onetrace.anonymous_header") or from the website tracker's cookie — the first one found.
     */
    public function anonymousId(): ?string
    {
        if ($this->anonymousId !== null) {
            return $this->anonymousId;
        }

        $request = $this->request();

        if (static::$anonymousIdResolver !== null) {
            $resolved = (static::$anonymousIdResolver)($request);

            if (($resolved = self::validAnonymousId(\is_scalar($resolved) ? (string) $resolved : null)) !== null) {
                return $resolved;
            }
        }

        if ($request === null) {
            return null;
        }

        $header = (string) $this->config('anonymous_header', '');

        if ($header !== '' && ($value = self::validAnonymousId($request->headers->get($header))) !== null) {
            return $value;
        }

        $name = (string) $this->config('anonymous_cookie', 'cdp_aid');

        if ($name === '') {
            return null;
        }

        // The provider keeps the cookie out of EncryptCookies (Laravel 11+); Octane passes cookies only this way.
        $cookie = $request->cookies->get($name);

        if (\is_string($cookie) && ($value = self::validAnonymousId($cookie)) !== null) {
            return $value;
        }

        // Laravel 10 under PHP-FPM without the cookie in EncryptCookies::$except: the raw header still has it.
        foreach (explode(';', (string) $request->headers->get('cookie')) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($key === $name) {
                return self::validAnonymousId(urldecode($value));
            }
        }

        return null;
    }

    protected static function validAnonymousId(?string $value): ?string
    {
        return $value !== null && preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $value) === 1 ? $value : null;
    }

    /**
     * Whether events are sent: enabled in the config and at least one key is set.
     */
    public function enabled(): bool
    {
        return (bool) $this->config('enabled', true) && ($this->config('write_key') || $this->config('secret_key'));
    }

    /**
     * @param array<string, mixed> $message
     */
    protected function record(string $type, array $message): void
    {
        if (!$this->enabled()) {
            return;
        }

        if (!isset($message['anonymousId']) && ($anonymousId = $this->anonymousId()) !== null) {
            $message['anonymousId'] = $anonymousId;
        }

        if ($type !== 'alias' && !isset($message['userId']) && ($id = $this->currentUser()?->getAuthIdentifier()) !== null && \is_scalar($id)) {
            $message['userId'] = (string) $id;
        }

        $request = $this->request();

        if ($request !== null) {
            $message['context'] = array_merge(array_filter(['ip' => $request->ip(), 'userAgent' => $request->userAgent()]), (array) ($message['context'] ?? []));
        }

        // Validated and stamped now: errors surface at the call, the time and messageId are those of the action.
        $this->events[] = Events::prepare($type, $message);
        $this->sendNowIfSync();
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    protected function deliverEvents(array $events): void
    {
        if ($this->config('send') === self::SEND_QUEUE) {
            $this->dispatch(new SendEvents($events));

            return;
        }

        $this->client()->events()->batch($events);
    }

    /**
     * @param list<array<string, mixed>> $upserts
     * @param list<string>               $deletes
     */
    protected function deliverProducts(array $upserts, array $deletes): void
    {
        $job = new SyncProducts($upserts, $deletes);

        if ($this->config('send') === self::SEND_QUEUE) {
            $this->dispatch($job);

            return;
        }

        $job->handle($this->client());
    }

    protected function dispatch(SendEvents|SyncProducts $job): void
    {
        $job->onConnection($this->config('queue.connection'))->onQueue($this->config('queue.name'));
        $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job);
    }

    protected function sendNowIfSync(): void
    {
        if ($this->config('send') === self::SEND_SYNC) {
            $this->flush();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function traitsOf(Authenticatable $user): array
    {
        if ($user instanceof HasOneTraceTraits) {
            return $user->oneTraceTraits();
        }

        $traits = [];

        foreach (['email', 'name'] as $field) {
            $value = $user->{$field} ?? null;

            if (\is_scalar($value) && $value !== '') {
                $traits[$field] = $value;
            }
        }

        return $traits;
    }

    protected function currentUser(): ?Authenticatable
    {
        if (!$this->app->bound('auth')) {
            return null;
        }

        try {
            $user = $this->app->make('auth')->user();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * The current request. Not tied to runningInConsole(): Octane serves requests from a CLI process, and in artisan
     * commands and queue workers the request has no cookies, headers or client address anyway.
     */
    protected function request(): ?Request
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        return $request instanceof Request ? $request : null;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get('onetrace.' . $key, $default);
    }
}
