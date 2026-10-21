<?php

return [

    /*
    | Address of your OneTrace.pro account: https://cdp.onetrace.pro or the domain of your white-label brand.
    */
    'url' => env('ONETRACE_URL', 'https://cdp.onetrace.pro'),

    /*
    | Keys from the project's "API keys" section. The write key (cdp_wk_…) sends events and is also used by the
    | tracker on your pages; the secret key (cdp_sk_…) is needed for products, profiles, segments, journeys and
    | campaigns. Without any key the package sends nothing.
    */
    'write_key' => env('ONETRACE_WRITE_KEY'),

    'secret_key' => env('ONETRACE_SECRET_KEY'),

    /*
    | Turn sending off (local development, CI) without removing calls from the code.
    */
    'enabled' => (bool) env('ONETRACE_ENABLED', true),

    /*
    | How events and product updates are sent:
    | - "after_response": collected during the request and sent in one batch after the response is delivered
    |   (after each job in queue workers, at the end of artisan commands);
    | - "queue": the same batch is pushed as a job to the queue below;
    | - "sync": sent immediately, the call waits for the API.
    */
    'send' => env('ONETRACE_SEND', 'after_response'),

    'queue' => [
        'connection' => env('ONETRACE_QUEUE_CONNECTION'),
        'name' => env('ONETRACE_QUEUE'),
    ],

    /*
    | Language of error messages from the API.
    */
    'language' => env('ONETRACE_LANGUAGE', 'en'),

    'timeout' => (float) env('ONETRACE_TIMEOUT', 10),

    'max_retries' => (int) env('ONETRACE_MAX_RETRIES', 3),

    /*
    | Cookie in which the website tracker keeps the visitor id: server events get it as anonymousId, so they join
    | the visitor's browsing history.
    */
    'anonymous_cookie' => 'cdp_aid',

    /*
    | Request header with the visitor id, for clients without the tracker cookie (mobile apps, SPAs calling your API),
    | e.g. "X-Anonymous-Id". OneTrace::useAnonymousId() and OneTrace::resolveAnonymousIdUsing() take precedence.
    */
    'anonymous_header' => env('ONETRACE_ANONYMOUS_HEADER'),

    /*
    | identify() the user automatically on Laravel's Login and Registered events. Traits come from
    | oneTraceTraits() of the user model when it implements OneTrace\Laravel\Contracts\HasOneTraceTraits,
    | otherwise email and name.
    */
    'identify' => [
        'on_login' => true,
        'on_register' => true,
    ],

    /*
    | The @onetrace Blade directive: link the browser to the signed-in user (only the user id is printed into the
    | page, no personal data) and reset the visitor after logout so the next person on the device starts anew.
    */
    'tracker' => [
        'identify' => true,
        'reset_on_logout' => true,
        // Crawlers, link previews, monitoring services and automated browsers are not tracked (tracker 1.4+);
        // false tracks everyone, e.g. to test the integration with Playwright or Selenium.
        'ignore_bots' => (bool) env('ONETRACE_IGNORE_BOTS', true),
        // The visitor's language: "page" (<html lang>, e.g. app()->getLocale() in your layout), "auto" (the
        // browser, the tracker's default), a code or false. Recommendations, widgets and search show the catalog
        // translation, and messages pick the template version in this language.
        'language' => env('ONETRACE_TRACKER_LANGUAGE'),
    ],

    /*
    | MCP server of the store for the OneTrace AI chat bot (store MCP contract v1): the bot answers customers with
    | live data of the store (orders, tracking, stock, delivery, returns) and, if allowed, cancels orders, creates
    | returns and changes delivery. Implement the tools in a class extending OneTrace\Laravel\StoreMcp\StoreMcpHandlers,
    | enable the server and run `php artisan onetrace:store-mcp` to connect it (secret key with chats.manage).
    */
    'store_mcp' => [
        'enabled' => (bool) env('ONETRACE_STORE_MCP', false),
        'path' => env('ONETRACE_STORE_MCP_PATH', 'onetrace/mcp'),
        // The key the platform sends to the server: 32+ random characters (php artisan onetrace:store-mcp --key).
        'key' => env('ONETRACE_STORE_MCP_KEY'),
        // Signs customer tokens (signed-in customers, confirmation codes); empty — derived from APP_KEY.
        'token_secret' => env('ONETRACE_CUSTOMER_TOKEN_SECRET'),
        'token_ttl' => 86400,
        'handlers' => null, // e.g. App\OneTrace\StoreTools::class
        // Tools the bot may use; null — every implemented read tool, actions only when listed here.
        'tools' => null,
        // Cache store of confirmation codes; null — the default store.
        'cache' => null,
        'name' => env('APP_NAME', 'laravel-store'),
    ],

];
