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
ONETRACE_WRITE_KEY=cdp_wk_…            # the website tracker, recommendations, search
ONETRACE_SECRET_KEY=cdp_sk_…           # events, products, profiles, segments, journeys, campaigns
ONETRACE_IDENTITY_KEY=cdp_ik_…         # signs the signed-in user's id for the tracker (Website → Connection → Server)
```

Events of the server go with the secret key when it is set: purchases sent with the public write key do not award loyalty points, and their user id is dropped when the project requires signed user ids.

To change the defaults, publish the config: `php artisan vendor:publish --tag=onetrace-config`. `php artisan about` shows the current settings.

## Website tracker

Put the directive into the `<head>` of your layout:

```blade
<head>
    …
    @onetrace
</head>
```

It prints the tracker install code with your write key and records the page view. For a signed-in user it links the browser to the user's id (only the id is printed into the page, no personal data), and on the first page after logout it resets the visitor so the next person on the device starts fresh. Options: `@onetrace(['nonce' => $nonce, 'page' => false, 'ignore_bots' => false, 'language' => 'page'])`; Vite's CSP nonce is used automatically. With `ONETRACE_IDENTITY_KEY` the user id comes signed — `cdp.identify(id, {}, { userHash })`: the platform trusts the user id of the browser only with the signature once the project turns on **Require signature** (Website → Connection → Server). `OneTrace::userHash($id)` gives the signature for your own pages or apps. `language` — the visitor's language for translated products and template versions: `page` (the `lang` of `<html>`, e.g. `app()->getLocale()` in your layout), `auto` (the browser, by default), a code or `false`.

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
// $result['items'], $result['total'], $result['facets'] (categories, brands, price, attributes)
// $result['corrected'] / $result['suggestion'] — typo fixed or "did you mean"; $result['filters'] — price, brand and
// characteristics recognized in the text; $result['unavailable'] — over the monthly search limit: show your own search
```

Or let the tracker draw the whole results page — turn on the search widget in **Site → Search** and put the container on your page; the "Add to cart" button posts the quantity to your address and expects `{"ok": true}` (or `{"redirect": "…"}` for a product that needs options):

```blade
<x-onetrace::search-results cart-url="/cart/add/{id}" />
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
            'description' => $this->description, // HTML or text: the AI answers shoppers' questions from it
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

Other languages of your store go with the product: `'translations' => ['en' => ['name' => 'Sneakers', 'url' => route('products.show', [$this, 'locale' => 'en']), 'description' => $this->description_en]]` in `toOneTraceProduct()`. Recommendations, widgets, search and emails show the translation in the visitor's or the message's language.

The description (and its translations) is sent as text: HTML tags and entities are removed, paragraphs and list items become lines, at most 5000 characters; an empty one is left out.

### Ask AI about the product

Shoppers ask questions on the product page and the AI answers from the product description and characteristics of the catalog and the knowledge base of your store, in the language of the question. Turn the widget on in **Site → Product questions** and put the element on the product page:

```blade
<x-onetrace::ask :product="$product->sku" />
```

`product` is the id of the catalog (`product_id` of events). The tracker of `@onetrace` draws suggested questions and the answer, or a chat button with the product, as set up in OneTrace; while the widget is off (or AI is not paid for) the element stays empty.

## Emails through OneTrace

The mail transport `onetrace` sends the application's emails through the project's email integration of OneTrace.pro as is — with delivery statuses and history in the customer profile. Put it in a failover mailer: when OneTrace does not accept an email (no email integration, the message quota is used up) or is unavailable, the next mailer sends it. The secret key needs the `messages.send` permission.

```php
// config/mail.php
'default' => env('MAIL_MAILER', 'failover'),
'mailers' => [
    'onetrace' => ['transport' => 'onetrace', 'kind' => 'other'],
    'failover' => ['transport' => 'failover', 'mailers' => ['onetrace', 'smtp']],
    // smtp, log …
],
```

Mark the kind of an email for reports and the profile history:

```php
use OneTrace\Laravel\Mail\SendsThroughOneTrace;

class ResetPassword extends Mailable
{
    use SendsThroughOneTrace;

    public function build(): static
    {
        return $this->subject('Reset your password')->view('mail.reset')->oneTraceKind('password_reset');
    }
}
```

- Every recipient (To, Cc, Bcc) gets their own copy; attachments are PDF, images, text, CSV or calendar files, up to 5 MB in total.
- Access-link kinds (`password_reset`, `account_new`, `email_verification`, `login_link`, `login_code`) go without click tracking and keep only the subject after sending.
- Order emails can be built from OneTrace templates instead: send `OneTrace::emails()->send()` with `order` data (see the library's README, "Store emails").

## Store MCP server for the AI chat bot

The AI chat bot of OneTrace can answer your customers with live data of your store — order status, tracking, stock, delivery, returns — and, if you allow it, cancel orders, create returns and change delivery. It calls an MCP server of your application by the [store MCP contract](https://cdp.onetrace.pro/docs/en/store-mcp). Implement the tools you offer:

```php
namespace App\OneTrace;

use App\Models\Order;
use App\Models\User;
use OneTrace\Laravel\StoreMcp\StoreMcpHandlers;
use OneTrace\StoreMcp\{Context, ToolError};

final class StoreTools extends StoreMcpHandlers
{
    public function getCustomer(Context $ctx): array
    {
        $user = User::findOrFail($ctx->customerId);

        return ['id' => (string) $user->id, 'email' => $user->email, 'first_name' => $user->first_name];
    }

    public function getOrder(array $args, Context $ctx): array
    {
        $order = Order::where('user_id', $ctx->customerId)->where('number', $args['order'])->first()
            ?? throw new ToolError(ToolError::NOT_FOUND, __('No such order.'));

        return ['order' => $order->number, 'status' => $order->status, 'total' => ['amount' => $order->total, 'currency' => 'EUR']];
    }

    // Confirmation by a code sent by email (override sendCode() for SMS).
    public function findCustomer(string $contact, ?string $order): ?string
    {
        return User::where('email', $contact)->value('id');
    }
}
```

```dotenv
ONETRACE_STORE_MCP=true
ONETRACE_STORE_MCP_KEY=   # php artisan onetrace:store-mcp --key
```

Set `'store_mcp.handlers' => App\OneTrace\StoreTools::class` in `config/onetrace.php` and run `php artisan onetrace:store-mcp` once (secret key with `chats.manage`): the platform checks the server at `POST /onetrace/mcp` and the bot starts using it.

- Method names follow the tools: `getCustomer`, `findOrders`, `getOrder`, `getTracking`, `checkAvailability`, `getDeliveryOptions`, `getReturnPolicy`, `cancelOrder`, `createReturn`, `changeDelivery`, `quoteOrder`, `createOrder`; a tool without a method is not offered. `$ctx->customerId` is the confirmed customer, `$ctx->language` — the conversation language.
- Read tools are offered by default; list actions in `store_mcp.tools` to allow them. Actions run only after the customer agreed in the chat, with `$ctx->idempotencyKey`; the result is kept in the cache for a day, so a repeated call with the same key gets it again without running your method (`store_mcp.idempotency => false` turns this off).
- `@onetrace` confirms a signed-in user for the bot: it passes a signed customer token to the tracker (`cdp.chatCustomer()`). Guests and messenger customers confirm with a code (`findCustomer()` + `sendCode()`).

### Orders from AI shopping assistants (contract v2)

AI shopping assistants (ChatGPT, Claude, Perplexity …) find your products through the store MCP of OneTrace. With `quoteOrder()` and `createOrder()` they can also price and place an order: the customer confirms the email or phone with a code, agrees to the items, address, delivery and total, and pays on your website by the payment link.

```php
use App\Models\Order;
use App\Services\Checkout;
use OneTrace\Laravel\Facades\OneTrace;
use OneTrace\Laravel\StoreMcp\StoreMcpHandlers;
use OneTrace\StoreMcp\{Context, ToolError};

final class StoreTools extends StoreMcpHandlers
{
    public function __construct(private readonly Checkout $checkout)
    {
    }

    // $args['items']: [['product_id' => 'sku-1', 'quantity' => 2], ...]; $args['shipping']: the given address fields
    public function quoteOrder(array $args, Context $ctx): array
    {
        $cart = $this->checkout->price($args['items'], $args['shipping'], $args['shipping_method']);

        return [
            'items' => $cart->lines(),                      // [{ product_id, name, quantity, price, total }]
            'unavailable' => $cart->unavailableIds(),
            'subtotal' => ['amount' => $cart->subtotal, 'currency' => 'EUR'],
            'discount' => ['amount' => $cart->discount, 'currency' => 'EUR'],
            'shipping_methods' => $cart->shippingMethods(), // [{ id, name, price, min_days, max_days }]
            'shipping_method' => $cart->shippingMethod,
            'shipping_price' => ['amount' => $cart->shippingPrice, 'currency' => 'EUR'],
            'total' => ['amount' => $cart->total, 'currency' => 'EUR'],
            'payment_methods' => [['id' => 'card', 'name' => 'Card on the website']],
        ];
    }

    // A customer ($ctx->customerId) or a guest ($ctx->guestEmail(), $ctx->guestPhone()); the customer agreed to the quote.
    public function createOrder(array $args, Context $ctx): array
    {
        $cart = $this->checkout->price($args['items'], $args['shipping'], $args['shipping_method']);

        if ($cart->priceChanged()) {
            throw new ToolError(ToolError::INVALID_INPUT, __('The price of a product has changed: quote the order again.'));
        }

        $order = Order::create([
            'user_id' => $ctx->customerId,
            'email' => $ctx->guestEmail(),
            'phone' => $ctx->guestPhone() ?? $args['shipping']['phone'] ?? null,
            'shipping' => $args['shipping'],
            'shipping_method' => $args['shipping_method'],
            'payment_method' => $args['payment_method'] ?? 'card',
            'comment' => $args['comment'],
            'total' => $cart->total,
            'source' => $ctx->source,       // "assistant"
            'assistant' => $ctx->assistant, // chatgpt, claude, perplexity, gemini, copilot or other
        ]);
        $order->lines()->createMany($cart->lines());

        OneTrace::track('order_completed', [
            'order_id' => $order->number,
            'amount' => $order->total,
            'ai_assistant' => $ctx->assistant, // the assistant's orders in OneTrace reports
        ], array_filter(['messageId' => 'order-' . $order->number, 'userId' => $ctx->customerId]));

        return [
            'order' => $order->number,
            'status' => 'pending',
            'total' => ['amount' => $order->total, 'currency' => 'EUR'],
            'payment_status' => 'pending',
            'payment_url' => route('orders.pay', $order), // the customer pays on your website
        ];
    }
}
```

```php
// config/onetrace.php, store_mcp — the assistants place orders only when you allow store_create_order
'tools' => ['store_start_verification', 'store_verify', 'store_get_customer', 'store_get_order', 'store_quote_order', 'store_create_order'],
```

- The server announces contract version 2 when both methods exist, otherwise version 1 without either tool. `store_quote_order` is a read tool; `store_create_order` is offered only when listed in `store_mcp.tools`, and then the assistant confirms the customer with a code.
- The arguments are checked before your method (`OneTrace\StoreMcp\OrderInput`): 1–20 products, quantity 1–99 (a repeated product added up), shipping fields as trimmed strings with the country in ISO 3166-1 alpha-2; an order needs `first_name`, `last_name`, `country`, `city`, `address` and `shipping_method`, `payment_method` and `comment` may be null. Anything else answers `invalid_input`. `product_id` is the id you send in the catalog (`toOneTraceProduct()`, the variant for a variable product).
- `createOrder()` runs only with the customer's consent and an idempotency key; a repeat with the same key returns the kept order. Check prices and stock again and throw `invalid_input` when they changed instead of placing the order at the old price.
- Guest orders: while `store_create_order` is offered, codes with `purpose: "order"` go to any email or phone, also without an account (your `sendCode()` gets an empty customer id), even when you do not implement `findCustomer()`. A guest has `$ctx->customerId === null` and `$ctx->guestContact` — the email (lower case) or phone (digits with `+`); the server answers `store_get_customer` for a guest itself (`{ id: null, email }`) when you offer it, other customer tools answer `auth_required`.
- `$ctx->source` is `assistant` for AI shopping assistants and `chat` for the chat bot, `$ctx->assistant` names the assistant: mark the order with it and send `ai_assistant` in the properties of its order event.

## The rest of the API

The facade passes everything else to the [`OneTrace\Client`](https://github.com/OneTracePro/onetrace-php):

```php
use OneTrace\Identity;

OneTrace::profiles()->get('email', 'anna@example.com');
OneTrace::profiles()->updateConsent('user_id', '42', 'email', 'unsubscribed');
OneTrace::profiles()->preferences('user_id', $user->id, app()->getLocale());      // topics by channel, frequency, pause, preferences_url
OneTrace::profiles()->updatePreferences('user_id', $user->id, ['frequency' => 'weekly', 'topics' => ['news' => ['email' => false]]]);
OneTrace::segments()->addMembers(12, [Identity::userId($user->id)]);
OneTrace::journeys()->enroll(7, Identity::userId($user->id), ['order_id' => 'A-1001'], 'order-A-1001');
OneTrace::recommendations()->get('viewed_with', ['item' => $product->sku, 'limit' => 4]);
OneTrace::analytics()->funnel(['steps' => [['event' => 'product_viewed'], ['event' => 'order_completed']], 'period' => ['last' => 30]]);
OneTrace::loyalty()->calculate(Identity::email($user->email), $cart->total());
OneTrace::loyalty()->refer(Identity::userId($user->id), OneTrace::referralCode()); // on registration: the code of a ?ref= link (cookie cdp_ref)
OneTrace::promo()->issue('welcome', Identity::userId($user->id));                   // a promo code of a pool
OneTrace::messages()->send(Identity::email($user->email), 12, ['data' => ['order_id' => $order->number]], "order-{$order->number}"); // a transactional message
OneTrace::chats()->reply($conversationId, 'Your order ships today.');            // answer a chat conversation as the API key
OneTrace::emails()->send(['to' => $user->email, 'kind' => 'order_shipped', 'order' => $order->emailData([...])]); // an order email from the OneTrace template
OneTrace::client(); // the client itself, also injectable as OneTrace\Client
```

A «Subscriptions» page in your customer account can show `preferences()` as a form and save it with `updatePreferences()`, or link to the hosted preference center: `preferences_url` is signed and valid for 30 days.

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
| `write_key` | `ONETRACE_WRITE_KEY` | — | tracker, recommendations, search; events without a secret key |
| `secret_key` | `ONETRACE_SECRET_KEY` | — | events, products and the management API |
| `identity_key` | `ONETRACE_IDENTITY_KEY` | — | signs the user id printed by `@onetrace` |
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

The tracker's cookies (`cdp_aid`, `cdp_ref`) are set by JavaScript and are not encrypted, so the package excludes them from `EncryptCookies` (Laravel 11+). On **Laravel 10** add them yourself — required under Octane, recommended everywhere:

```php
// app/Http/Middleware/EncryptCookies.php
protected $except = ['cdp_aid', 'cdp_ref'];
```

## Development

```bash
composer install
composer check   # PHPStan and PHPUnit (Testbench)
```

## License

MIT, see [LICENSE](LICENSE).
