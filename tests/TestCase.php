<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use OneTrace\Http\Transport;
use OneTrace\Laravel\OneTraceServiceProvider;
use OneTrace\Laravel\Testing\RecordingTransport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected RecordingTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new RecordingTransport();
        $this->app->instance(Transport::class, $this->transport);
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OneTraceServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['OneTrace' => \OneTrace\Laravel\Facades\OneTrace::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app->make('config'), static function (Repository $config): void {
            $config->set('onetrace.url', 'https://cdp.example.com');
            $config->set('onetrace.write_key', 'cdp_wk_test');
            $config->set('onetrace.secret_key', 'cdp_sk_test');
            $config->set('onetrace.max_retries', 0);
            $config->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
            // Testbench 8 (Laravel 10) defaults to MySQL.
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        });
    }

    /**
     * Messages of all /batch requests sent so far.
     *
     * @return list<array<string, mixed>>
     */
    protected function sentMessages(): array
    {
        $messages = [];

        foreach ($this->transport->requests as $request) {
            if (str_ends_with($request->getUrl(), '/api/v1/batch')) {
                $body = json_decode((string) $request->getBody(), true);
                array_push($messages, ...$body['batch']);
            }
        }

        return $messages;
    }
}
