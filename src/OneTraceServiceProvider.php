<?php

declare(strict_types=1);

namespace OneTrace\Laravel;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use OneTrace\Client;
use OneTrace\Exception\ConfigurationException;
use OneTrace\Http\Transport;
use OneTrace\Laravel\Console\SyncProductsCommand;
use OneTrace\Laravel\Listeners\AuthListener;

class OneTraceServiceProvider extends ServiceProvider
{
    public const VERSION = '1.2.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/onetrace.php', 'onetrace');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            $config = $app->make('config');
            $options = [
                'write_key' => $config->get('onetrace.write_key') ?: null,
                'secret_key' => $config->get('onetrace.secret_key') ?: null,
                'timeout' => (float) $config->get('onetrace.timeout', 10),
                'max_retries' => (int) $config->get('onetrace.max_retries', 3),
                'language' => (string) $config->get('onetrace.language', 'en'),
                'user_agent' => sprintf('onetrace-laravel/%s laravel/%s', self::VERSION, $app->version()),
            ];

            if ($options['write_key'] === null && $options['secret_key'] === null) {
                throw new ConfigurationException('Set ONETRACE_WRITE_KEY and/or ONETRACE_SECRET_KEY to use the OneTrace.pro API.');
            }

            // Tests and custom networking: bind OneTrace\Http\Transport in the container.
            if ($app->bound(Transport::class)) {
                $options['transport'] = $app->make(Transport::class);
            }

            return new Client((string) $config->get('onetrace.url'), $options);
        });

        // One collector per request (Octane) and per job (queue workers); flushed before it is dropped.
        $this->app->scoped(OneTrace::class, static fn (Application $app): OneTrace => new OneTrace($app));
        // Not a singleton: under Octane it would keep the container (and the request) of an earlier request.
        $this->app->bind(Tracker::class, static fn (Application $app): Tracker => new Tracker($app));
    }

    public function boot(): void
    {
        // The tracker sets its cookie from JavaScript, unencrypted: EncryptCookies would replace it with null.
        // Laravel 11+; on Laravel 10 add the cookie to $except of the application's EncryptCookies (see README).
        $cookie = $this->app->make('config')->get('onetrace.anonymous_cookie');

        // @phpstan-ignore function.alreadyNarrowedType (absent in Laravel 10)
        if (\is_string($cookie) && $cookie !== '' && method_exists(EncryptCookies::class, 'except')) {
            EncryptCookies::except($cookie);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/onetrace.php' => $this->app->configPath('onetrace.php')], 'onetrace-config');
            $this->commands([SyncProductsCommand::class]);
            $this->registerAbout();
        }

        $this->callAfterResolving('blade.compiler', static function (BladeCompiler $blade): void {
            $blade->directive('onetrace', static function (string $expression): string {
                return '<?php echo app(\\OneTrace\\Laravel\\Tracker::class)->render(' . ($expression !== '' ? $expression : '[]') . '); ?>';
            });
        });

        $events = $this->app->make('events');
        $events->listen(Login::class, [AuthListener::class, 'login']);
        $events->listen(Registered::class, [AuthListener::class, 'registered']);
        $events->listen(Logout::class, [AuthListener::class, 'logout']);

        // After the response (and at the end of artisan commands), and after every queued job. The application is
        // taken at call time: under Octane each request runs in a clone of the booted one, holding its own collector.
        $this->app->terminating(static function (Application $app): void {
            self::flush($app);
        });
        $events->listen([JobProcessed::class, JobExceptionOccurred::class], static function (): void {
            $app = Container::getInstance();

            if ($app instanceof Application) {
                self::flush($app);
            }
        });
    }

    private static function flush(Application $app): void
    {
        if ($app->resolved(OneTrace::class)) {
            $app->make(OneTrace::class)->flushQuietly();
        }
    }

    private function registerAbout(): void
    {
        $about = 'Illuminate\Foundation\Console\AboutCommand';

        if (!class_exists($about)) {
            return;
        }

        $about::add('OneTrace', function (): array {
            $config = $this->app->make('config');

            return [
                'Version' => self::VERSION,
                'URL' => (string) $config->get('onetrace.url'),
                'Enabled' => $config->get('onetrace.enabled') ? 'yes' : 'no',
                'Write key' => $config->get('onetrace.write_key') ? 'set' : 'not set',
                'Secret key' => $config->get('onetrace.secret_key') ? 'set' : 'not set',
                'Sending' => (string) $config->get('onetrace.send'),
            ];
        });
    }
}
