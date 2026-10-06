<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Cookie\CookieJar;
use Illuminate\Support\Facades\Schema;
use OneTrace\Laravel\Facades\OneTrace;
use OneTrace\Laravel\Tests\Fixtures\User;
use OneTrace\Laravel\Tracker;

final class AuthTest extends TestCase
{
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

    public function testIdentifiesOnLoginAndRegistration(): void
    {
        $fake = OneTrace::fake();
        $user = User::create(['name' => 'Anna', 'email' => 'anna@example.com']);

        event(new Registered($user));
        event(new Login('web', $user, false));

        $fake->assertIdentified(static fn (array $message): bool => $message['userId'] === (string) $user->id && $message['traits']['email'] === 'anna@example.com');
        self::assertCount(2, $fake->sent());
    }

    public function testAutomaticIdentifyCanBeTurnedOff(): void
    {
        config(['onetrace.identify.on_login' => false, 'onetrace.identify.on_register' => false]);
        $fake = OneTrace::fake();
        $user = User::create(['email' => 'anna@example.com']);

        event(new Registered($user));
        event(new Login('web', $user, false));

        $fake->assertNothingSent();
    }

    public function testLogoutAsksTheNextPageToResetTheTracker(): void
    {
        event(new Logout('web', User::create(['email' => 'anna@example.com'])));

        self::assertTrue($this->app->make(CookieJar::class)->hasQueued(Tracker::RESET_COOKIE));
    }
}
