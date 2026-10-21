<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OneTrace\Client;
use OneTrace\Laravel\StoreMcp\StoreMcp;

/**
 * Connects the store MCP server of the application to the OneTrace AI chat bot (secret key with chats.manage).
 */
final class StoreMcpCommand extends Command
{
    protected $signature = 'onetrace:store-mcp
        {--key : Print a new random key for ONETRACE_STORE_MCP_KEY and stop}
        {--disconnect : Disconnect the server from the bot}';

    protected $description = 'Connect the store MCP server to the OneTrace AI chat bot';

    public function handle(Client $client, StoreMcp $mcp): int
    {
        if ($this->option('key')) {
            $this->line(Str::random(48));

            return self::SUCCESS;
        }

        if (!$mcp->enabled()) {
            $this->error('Set ONETRACE_STORE_MCP=true, ONETRACE_STORE_MCP_KEY (32+ characters) and onetrace.store_mcp.handlers first.');

            return self::FAILURE;
        }

        $url = route('onetrace.store-mcp');

        if ($this->option('disconnect')) {
            $client->chats()->disconnectStoreMcp($url);
            $this->info("Disconnected {$url}.");

            return self::SUCCESS;
        }

        $config = $this->laravel->make('config');
        $result = $client->chats()->connectStoreMcp($url, (string) $config->get('onetrace.store_mcp.key'), (string) $config->get('onetrace.store_mcp.name'), 'sdk');

        if (($result['status'] ?? null) !== 'ok') {
            $this->error('The platform could not use the server: ' . (\is_string($result['error'] ?? null) ? $result['error'] : 'unknown error'));

            return self::FAILURE;
        }

        $this->info("Connected {$url}: " . implode(', ', array_filter((array) ($result['tools'] ?? []), 'is_string')));

        return self::SUCCESS;
    }
}
