<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use OneTrace\Laravel\Tests\Fixtures\User;
use OneTrace\Laravel\Tracker;

final class TrackerTest extends TestCase
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

    public function testRendersTheInstallCode(): void
    {
        $html = Blade::render('<head>@onetrace</head>');

        self::assertStringContainsString('"https://cdp.example.com/tracker/cdp.js"', $html);
        self::assertStringContainsString('cdp.init("cdp_wk_test", { host: "https://cdp.example.com" });', $html);
        self::assertStringContainsString('cdp.page();', $html);
        self::assertStringNotContainsString('cdp.identify', $html);
    }

    public function testLinksTheBrowserToTheSignedInUserWithoutPersonalData(): void
    {
        $this->actingAs(User::create(['name' => 'Anna', 'email' => 'anna@example.com']));

        $html = Blade::render('@onetrace');

        self::assertStringContainsString("localStorage.getItem('cdp_uid')!==\"1\")cdp.identify(\"1\")", $html);
        self::assertStringNotContainsString('anna@example.com', $html);
    }

    public function testOptionsAndEscaping(): void
    {
        config(['onetrace.write_key' => 'cdp_wk_</script><script>alert(1)']);

        $html = Blade::render("@onetrace(['nonce' => 'abc', 'page' => false])");

        self::assertStringStartsWith('<script nonce="abc">', $html);
        self::assertStringNotContainsString('cdp.page()', $html);
        self::assertStringNotContainsString('</script><script>', $html);
    }

    public function testBotsCanBeTracked(): void
    {
        self::assertStringNotContainsString('ignoreBots', Blade::render('@onetrace'));

        config(['onetrace.tracker.ignore_bots' => false]);
        self::assertStringContainsString('cdp.init("cdp_wk_test", { host: "https://cdp.example.com", ignoreBots: false });', Blade::render('@onetrace'));

        config(['onetrace.tracker.ignore_bots' => true]);
        self::assertStringContainsString('ignoreBots: false', Blade::render("@onetrace(['ignore_bots' => false])"));
    }

    public function testRendersNothingWithoutAWriteKey(): void
    {
        config(['onetrace.write_key' => null]);

        self::assertSame('', trim(Blade::render('@onetrace')));
    }

    public function testResetsTheVisitorOnTheFirstPageAfterLogout(): void
    {
        $this->app['request']->cookies->set(Tracker::RESET_COOKIE, '1');

        $html = Blade::render('@onetrace');

        self::assertStringContainsString('cdp.reset();', $html);
        self::assertTrue($this->app->make(\Illuminate\Cookie\CookieJar::class)->hasQueued(Tracker::RESET_COOKIE), 'The reset cookie is removed.');
    }
}
