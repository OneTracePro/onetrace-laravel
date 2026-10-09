# OneTrace.pro for Laravel

[![CI](https://github.com/OneTracePro/onetrace-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/OneTracePro/onetrace-laravel/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/onetracepro/onetrace-laravel.svg)](https://packagist.org/packages/onetracepro/onetrace-laravel)
[![License](https://img.shields.io/packagist/l/onetracepro/onetrace-laravel.svg)](LICENSE)

Laravel integration for [OneTrace.pro](https://onetrace.pro), the customer data platform. Track orders and sign-ups from your backend without slowing responses down, put the website tracker on your pages with one Blade directive, keep the product catalog in sync with your Eloquent models, and test it all with a fake.

Built on [`onetracepro/onetrace-php`](https://github.com/OneTracePro/onetrace-php): everything that library can do is available through the facade.

- Laravel 10–13, PHP 8.1–8.5
- Events go out in one batch after the response, through your queue, or immediately
- `anonymousId` from the tracker cookie and the signed-in user are added to events automatically
- `identify` on login and registration, tracker reset after logout
- `OneTrace::fake()` for your application's tests

## Installation

```bash
composer require onetracepro/onetrace-laravel
```

Add the keys from your project's **API keys** section to `.env`:

```dotenv
ONETRACE_URL=https://cdp.onetrace.pro   # or the domain of your white-label brand
ONETRACE_WRITE_KEY=cdp_wk_…            # events and the website tracker
ONETRACE_SECRET_KEY=cdp_sk_…           # products, profiles, segments, journeys, campaigns
```

To change the defaults, publish the config: `php artisan vendor:publish --tag=onetrace-config`. `php artisan about` shows the current settings.

## Website tracker

Put the directive into the `<head>` of your layout:

```blade
<head>
    …
    @onetrace
</head>
```

It prints the tracker install code with your write key and records the page view. For a signed-in user it links the browser to the user's id (only the id is printed into the page, no personal data), and on the first page after logout it resets the visitor so the next person on the device starts fresh. Options: `@onetrace(['nonce' => $nonce, 'page' => false, 'ignore_bots' => false, 'language' => 'page'])`; Vite's CSP nonce is used automatically. `language` — the visitor's language for translated products and template versions: `page` (the `lang` of `<html>`, e.g. `app()->getLocale()` in your layout), `auto` (the browser, by default), a code or `false`.

Search engine crawlers, link previews, monitoring services and automated browsers (Playwright, Selenium) are not tracked: the tracker sends no events and sets no cookies for them, while recommendation widgets are still shown. To track everyone — for example, to test the integration with an automated browser — set `ONETRACE_IGNORE_BOTS=false` (or `'ignore_bots' => false` in the directive) in that environment. Product events, widgets and Web Push in the browser are described in your account under **Site**.

## Product search

The plan of your project must include product search. A search box with suggestions while typing (products, categories, popular queries):

```blade
<x-onetrace::search-box action="/search" category-url="/catalog/{id}" placeholder="Search" class="w-full" />
```

Your results page:

```php
$result = OneTrace::searchProducts($request->query('q', ''), [
    'page' => $request->integer('page', 1), 'per_page' => 24, 'sort' => 'relevance', 'category' => $request->query('category'),
]);
// $result['items'], $result['total'], $result['facets'] (categories, brands, price)
```

The visitor of the tracker cookie (the order by their interests) and `app()->getLocale()` (names in the catalog translation) are added automatically; the first page records the `search` event, from which the popular queries of the suggestions come. Suggestions from the backend: `OneTrace::search()->suggest('lin')`.

## Events from the backend

```php
use OneTrace\Laravel\Facades\OneTrace;

OneTrace::track('order_completed', [
    'order_id' => $order->number,
    'amount' => $order->total,
    'products' => $order->lines->map(fn ($line) => [
        'product_id' => $line->sku, 'quantity' => $line->quantity, 'price' => $line->price,
    ])->all(),
], ['messageId' => 'order-' . $order->number]); // a repeat within 24 hours is ignored

OneTrace::identify($user, ['plan' => 'pro']);           // the user's email and name are added
OneTrace::identify(null, ['email' => $request->email]); // a visitor who left an email in a form
OneTrace::page('Checkout');
OneTrace::alias($oldUserId);                            // merge an old id into the signed-in user
```

Each event gets the signed-in user (`userId`), the visitor id (`anonymousId`, see [below](#visitor-id-before-sign-in)), the IP and user agent, a `messageId` and a timestamp. Calls validate the event right away and return immediately; sending depends on `ONETRACE_SEND`:

| `ONETRACE_SEND` | |
|---|---|
| `after_response` (default) | one batch after the response is sent to the browser; after each job in queue workers; at the end of artisan commands |
| `queue` | the same batch is pushed as a job (`ONETRACE_QUEUE_CONNECTION`, `ONETRACE_QUEUE`); retries keep the same `messageId`s |
| `sync` | sent immediately, the call waits for the API |

Errors of background sending are reported to your exception handler; they never break the response. `OneTrace::flush()` sends right away (useful in long-running loops). `ONETRACE_ENABLED=false` turns sending off; without keys nothing is sent either.

### Visitor id before sign-in

Events before sign-in belong to an anonymous visitor; when the same `anonymousId` comes with the user's `identify`, the visitor's history is merged into the user's profile. On websites with the tracker this works by itself — the id comes from the tracker's cookie. Elsewhere, tell the package where the id is:

```php
// For the rest of the request or job — including the automatic identify on login and registration:
OneTrace::useAnonymousId($request->input('anonymous_id'));

// Mobile apps and SPAs send it in a header — in .env: ONETRACE_ANONYMOUS_HEADER=X-Anonymous-Id

// Any other source, once in a service provider:
OneTrace::resolveAnonymousIdUsing(fn (?Request $request) => $request?->session()->get('visitor_id'));

// One call only:
OneTrace::identify($user, [], ['anonymousId' => $visitorId]);
```

The first one found wins: `useAnonymousId()`, the resolver, the header, the tracker cookie. Registration handled in a queued job: pass the id with the job and call `OneTrace::useAnonymousId()` in it. An id the user had on another device can be merged later with `OneTrace::alias($oldId)`.

### Automatic identify

On Laravel's `Login` and `Registered` events the user is identified with `email` and `name`. To choose the traits, implement `HasOneTraceTraits` on the user model:

```php
use OneTrace\Laravel\Contracts\HasOneTraceTraits;

class User extends Authenticatable implements HasOneTraceTraits
{
    public function oneTraceTraits(): array
    {
        return ['email' => $this->email, 'phone' => $this->phone, 'first_name' => $this->first_name, 'plan' => $this->plan];
    }
}
```

`email`, `phone`, `telegram_chat_id` and `viber_id` identify the profile; the rest become profile attributes. Turn it off with `onetrace.identify.on_login` / `on_register`.

## Product catalog

```php
use OneTrace\Laravel\Contracts\OneTraceProduct;
use OneTrace\Laravel\Eloquent\SyncsWithOneTrace;

class Product extends Model implements OneTraceProduct
{
    use SyncsWithOneTrace;

    public function toOneTraceProduct(): array
    {
        return [
            'id' => $this->sku,                 // the product_id your events use
            'name' => $this->title,
            'price' => $this->price,
            'currency' => 'EUR',
            'url' => route('products.show', $this),
            'image' => $this->image_url,
            'category_ids' => [$this->category_slug],
            'available' => $this->stock > 0,
        ];
    }

    public function shouldSyncWithOneTrace(): bool
    {
        return $this->is_published; // optional
    }
}
```

Saved models are created or updated in the catalog, deleted ones removed (soft-deleted too; restored ones come back) — sent the same way as events, in batches of up to 1000. Upload the whole catalog once, or on a schedule:

```bash
php artisan onetrace:sync-products "App\Models\Product"
```

Without models: `OneTrace::syncProducts([['id' => 'SKU-1', 'name' => '…']])` and `OneTrace::deleteProducts(['SKU-1'])`. Products need the secret key.

Other languages of your store go with the product: `'translations' => ['en' => ['name' => 'Sneakers', 'url' => route('products.show', [$this, 'locale' => 'en'])]]` in `toOneTraceProduct()`. Recommendations, widgets, search and emails show the translation in the visitor's or the message's language.

## The rest of the API

The facade passes everything else to the [`OneTrace\Client`](https://github.com/OneTracePro/onetrace-php):

```php
use OneTrace\Identity;

OneTrace::profiles()->get('email', 'anna@example.com');
OneTrace::profiles()->updateConsent('user_id', '42', 'email', 'unsubscribed');
OneTrace::segments()->addMembers(12, [Identity::userId($user->id)]);
OneTrace::journeys()->enroll(7, Identity::userId($user->id), ['order_id' => 'A-1001'], 'order-A-1001');
OneTrace::recommendations()->get('viewed_with', ['item' => $product->sku, 'limit' => 4]);
OneTrace::client(); // the client itself, also injectable as OneTrace\Client
```

See the [library's README](https://github.com/OneTracePro/onetrace-php#readme) for errors, retries and pagination, and the [API reference](https://onetrace.pro/en/docs/api).

## Testing your application

```php
use OneTrace\Laravel\Facades\OneTrace;

public function test_checkout_tracks_the_order(): void
{
    $fake = OneTrace::fake();

    $this->actingAs($user)->post('/checkout', $payload);

    $fake->assertTracked('order_completed', fn (array $message) => $message['properties']['amount'] === 9980);
    $fake->assertIdentified($user->id);
    $fake->assertProductSynced('SKU-1');
    $fake->assertNotTracked('refund_issued');
}
```

Also: `assertTrackedTimes()`, `assertPageViewed()`, `assertProductDeleted()`, `assertNothingSent()`, `sent()`. Calls through the client (`OneTrace::segments()->…`) are recorded too: `$fake->respondWith([...])` sets the response, `$fake->requests()` returns what was sent.

## Configuration

| Key | Env | Default | |
|---|---|---|---|
| `url` | `ONETRACE_URL` | `https://cdp.onetrace.pro` | your account's address |
| `write_key` | `ONETRACE_WRITE_KEY` | — | events, tracker |
| `secret_key` | `ONETRACE_SECRET_KEY` | — | products and the management API |
| `enabled` | `ONETRACE_ENABLED` | `true` | |
| `send` | `ONETRACE_SEND` | `after_response` | `after_response`, `queue`, `sync` |
| `queue.connection`, `queue.name` | `ONETRACE_QUEUE_CONNECTION`, `ONETRACE_QUEUE` | default | for `queue` |
| `language` | `ONETRACE_LANGUAGE` | `en` | language of API error messages |
| `timeout`, `max_retries` | `ONETRACE_TIMEOUT`, `ONETRACE_MAX_RETRIES` | `10`, `3` | |
| `anonymous_cookie` | | `cdp_aid` | the tracker's visitor cookie |
| `anonymous_header` | `ONETRACE_ANONYMOUS_HEADER` | — | request header with the visitor id (mobile apps, SPAs) |
| `identify.on_login`, `identify.on_register` | | `true` | automatic identify |
| `tracker.identify`, `tracker.reset_on_logout` | | `true` | `@onetrace` behaviour |
| `tracker.ignore_bots` | `ONETRACE_IGNORE_BOTS` | `true` | skip crawlers and automated browsers in the tracker |
| `tracker.language` | `ONETRACE_TRACKER_LANGUAGE` | — | the visitor's language in `@onetrace`: `page`, `auto`, a code |

### Octane and queue workers

The package works under Laravel Octane (checked on Swoole) and in long-running queue workers: the collector is scoped to the request or job, events are sent when each request terminates or each job finishes, and `useAnonymousId()` never leaks into the next request.

The tracker's cookie is set by JavaScript and is not encrypted, so the package excludes it from `EncryptCookies` (Laravel 11+). On **Laravel 10** add it yourself — required under Octane, recommended everywhere:

```php
// app/Http/Middleware/EncryptCookies.php
protected $except = ['cdp_aid'];
```

## Development

```bash
composer install
composer check   # PHPStan and PHPUnit (Testbench)
```

## License

MIT, see [LICENSE](LICENSE).
