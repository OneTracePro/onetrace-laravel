<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Support\Facades\Blade;
use OneTrace\Laravel\Facades\OneTrace;

final class SearchTest extends TestCase
{
    public function testSearchesAsTheVisitorInTheApplicationLocaleAndCountsTheFirstPage(): void
    {
        $this->app->setLocale('de');
        OneTrace::useAnonymousId('browser-1');
        $this->transport->respond(200, ['request_id' => 'req-1', 'total' => 3, 'items' => [['id' => 'SKU-1', 'name' => 'Turnschuhe']]]);
        $this->transport->respond(200, ['total' => 3, 'items' => []]);

        $result = OneTrace::searchProducts('  turnschuhe ', ['per_page' => 12]);
        OneTrace::searchProducts('turnschuhe', ['page' => 2, 'language' => 'en']);
        OneTrace::flush();

        $urls = array_map(static fn ($request): string => urldecode($request->getUrl()), $this->transport->requests);
        $searches = array_values(array_filter($this->sentMessages(), static fn (array $message): bool => ($message['event'] ?? null) === 'search'));

        self::assertSame('Turnschuhe', $result['items'][0]['name']);
        self::assertStringContainsString('/api/v1/search?q=  turnschuhe &per_page=12&anonymousId=browser-1&language=de', $urls[0]);
        self::assertStringContainsString('page=2&language=en&anonymousId=browser-1', $urls[1]);
        self::assertCount(1, $searches);
        self::assertSame(['query' => 'turnschuhe', 'results' => 3, 'request_id' => 'req-1'], $searches[0]['properties']);
        self::assertSame('browser-1', $searches[0]['anonymousId']);
    }

    public function testRendersASearchBoxWithSuggestionsOfTheTracker(): void
    {
        $this->app->instance('request', \Illuminate\Http\Request::create('/search', 'GET', ['q' => 'shoes']));

        $html = Blade::render('<x-onetrace::search-box action="/catalog/search" category-url="/c/{id}" class="w-full" placeholder="Search" />');

        self::assertStringContainsString('<form action="/catalog/search" method="get" role="search" class="w-full">', $html);
        self::assertStringContainsString('name="q" value="shoes" data-cdp-search', $html);
        self::assertStringContainsString('data-category-url="/c/{id}"', $html);
        self::assertStringContainsString('placeholder="Search"', $html);
    }

    public function testPassesTheLanguageOfTheVisitorToTheTracker(): void
    {
        self::assertStringContainsString('cdp.init("cdp_wk_test", { host: "https://cdp.example.com", language: "page" });', Blade::render("@onetrace(['language' => 'page'])"));

        config(['onetrace.tracker.language' => 'de']);

        self::assertStringContainsString(', language: "de" });', Blade::render('@onetrace'));
        self::assertStringContainsString(', language: false });', Blade::render("@onetrace(['language' => false])"));
    }
}
