<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use OneTrace\Laravel\Facades\OneTrace;
use OneTrace\Laravel\OneTrace as Manager;
use OneTrace\Laravel\Tests\Fixtures\User;

final class AnonymousIdTest extends TestCase
{
    protected function tearDown(): void
    {
        Manager::resolveAnonymousIdUsing(null);

        parent::tearDown();
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', static function ($table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function defineRoutes($router): void
    {
        $router->post('/api/register', static function (Request $request) {
            $user = User::create(['email' => $request->input('email')]);
            OneTrace::useAnonymousId($request->input('anonymous_id'));
            event(new Login('web', $user, false));
            OneTrace::track('signed_up');

            return response('ok');
        });

        $router->get('/web/ping', static function () {
            OneTrace::track('ping', [], ['userId' => '1']);

            return response('ok');
        })->middleware('web');

        $router->get('/api/ping', static function () {
            OneTrace::track('ping', [], ['userId' => '1']);

            return response('ok');
        });
    }

    public function testAnExplicitIdReachesTheAutomaticIdentifyAndLaterEvents(): void
    {
        $this->postJson('/api/register', ['email' => 'anna@example.com', 'anonymous_id' => 'app-7f3a'])->assertOk();

        [$identify, $track] = $this->sentMessages();
        self::assertSame(['identify', 'app-7f3a', 'anna@example.com'], [$identify['type'], $identify['anonymousId'], $identify['traits']['email']]);
        self::assertSame(['signed_up', 'app-7f3a'], [$track['event'], $track['anonymousId']]);
    }

    public function testOutsideRequestsTheIdIsSetForTheJob(): void
    {
        OneTrace::useAnonymousId('a-from-job');
        OneTrace::identify('42', ['plan' => 'pro']);
        OneTrace::flush();

        self::assertSame('a-from-job', $this->sentMessages()[0]['anonymousId']);
    }

    public function testTheHeaderFromTheConfig(): void
    {
        config(['onetrace.anonymous_header' => 'X-Anonymous-Id']);

        $this->getJson('/api/ping', ['X-Anonymous-Id' => 'mobile-1'])->assertOk();

        self::assertSame('mobile-1', $this->sentMessages()[0]['anonymousId']);
    }

    public function testACustomResolver(): void
    {
        OneTrace::resolveAnonymousIdUsing(static fn (?Request $request): ?string => $request?->query('aid'));

        $this->getJson('/api/ping?aid=from-query')->assertOk();

        self::assertSame('from-query', $this->sentMessages()[0]['anonymousId']);
    }

    public function testPriorityExplicitThenResolverThenHeaderThenCookie(): void
    {
        config(['onetrace.anonymous_header' => 'X-Anonymous-Id']);
        $this->app->instance('request', Request::create('/', 'GET', [], ['cdp_aid' => 'from-cookie'], [], ['HTTP_COOKIE' => 'cdp_aid=from-cookie', 'HTTP_X_ANONYMOUS_ID' => 'from-header']));
        $manager = $this->app->make(Manager::class);

        self::assertSame('from-header', $manager->anonymousId());

        Manager::resolveAnonymousIdUsing(static fn (): string => 'from-resolver');
        self::assertSame('from-resolver', $manager->anonymousId());

        $manager->useAnonymousId('explicit');
        self::assertSame('explicit', $manager->anonymousId());

        $manager->useAnonymousId(null);
        Manager::resolveAnonymousIdUsing(static fn (): ?string => null);
        config(['onetrace.anonymous_header' => null]);
        self::assertSame('from-cookie', $manager->anonymousId());
    }

    public function testTheTrackerCookieSurvivesEncryptCookies(): void
    {
        if (!method_exists(\Illuminate\Cookie\Middleware\EncryptCookies::class, 'except')) {
            self::markTestSkipped('Laravel 10: the application adds the cookie to EncryptCookies::$except itself.');
        }

        // Cookies only in the cookie bag, as Octane passes them, through the web middleware with EncryptCookies.
        $this->withUnencryptedCookie('cdp_aid', 'visitor-9')->get('/web/ping')->assertOk();

        self::assertSame('visitor-9', $this->sentMessages()[0]['anonymousId']);
    }

    public function testRejectsInvalidIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        OneTrace::useAnonymousId('<script>');
    }
}
