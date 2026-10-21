<?php

declare(strict_types=1);

namespace OneTrace\Laravel\StoreMcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\Contract;
use OneTrace\StoreMcp\CustomerTokens;
use OneTrace\StoreMcp\Server;
use OneTrace\StoreMcp\Verification;

/**
 * The store MCP server from config('onetrace.store_mcp'): handlers, allowed tools, customer tokens and codes.
 */
final class StoreMcp
{
    public function __construct(private readonly Application $app)
    {
    }

    public function enabled(): bool
    {
        $config = $this->config();

        return (bool) ($config['enabled'] ?? false) && \is_string($config['key'] ?? null) && \strlen($config['key']) >= 32 && $this->handlers() !== null;
    }

    public function server(): Server
    {
        $config = $this->config();
        $handlers = $this->handlers();

        if ($handlers === null) {
            throw new \LogicException('Set onetrace.store_mcp.handlers to a class extending ' . StoreMcpHandlers::class . '.');
        }

        $tools = [];

        foreach (array_keys(Contract::tools()) as $name) {
            $method = Str::camel(Str::after($name, 'store_'));
            $callable = [$handlers, $method];

            if (\in_array($name, ['store_start_verification', 'store_verify'], true) || !\is_callable($callable)) {
                continue;
            }

            $handler = \Closure::fromCallable($callable);
            $tools[$name] = $method === 'getCustomer'
                ? static fn (array $args, Context $ctx): array => $handler($ctx)
                : static fn (array $args, Context $ctx): array => $handler($args, $ctx);
        }

        // Without an explicit list: read tools only — actions are offered when the owner lists them.
        $enabled = \is_array($config['tools'] ?? null) ? array_values(array_filter($config['tools'], 'is_string'))
            : array_keys(array_filter(Contract::tools(), static fn (array $tool): bool => $tool['read_only']));

        return new Server((string) $config['key'], $this->tokens(), $tools, [
            'name' => (string) ($config['name'] ?? 'laravel-store'),
            'enabled' => $enabled,
            'verification' => $handlers->verifiesByCode() ? new Verification(
                new CacheCodeStore($this->app->make('cache')->store(\is_string($config['cache'] ?? null) ? $config['cache'] : null)),
                [$handlers, 'findCustomer'],
                [$handlers, 'sendCode'],
            ) : null,
        ]);
    }

    /**
     * The chat token of the signed-in customer for cdp.chatCustomer().
     */
    public function customerToken(?Authenticatable $user): ?string
    {
        $handlers = $this->handlers();
        $id = $user !== null && $handlers !== null ? $handlers->customerId($user) : null;

        return $id !== null && $id !== '' ? $this->tokens()->issue($id, (int) ($this->config()['token_ttl'] ?? 86400)) : null;
    }

    public function tokens(): CustomerTokens
    {
        $secret = $this->config()['token_secret'] ?? null;
        $appKey = $this->app->make('config')->get('app.key');

        return new CustomerTokens(\is_string($secret) && $secret !== '' ? $secret : hash_hmac('sha256', 'onetrace-customer-tokens', \is_string($appKey) ? $appKey : ''));
    }

    private function handlers(): ?StoreMcpHandlers
    {
        $class = $this->config()['handlers'] ?? null;

        if (!\is_string($class) || !is_subclass_of($class, StoreMcpHandlers::class)) {
            return null;
        }

        return $this->app->make($class);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $config = $this->app->make('config')->get('onetrace.store_mcp');

        return \is_array($config) ? $config : [];
    }
}
