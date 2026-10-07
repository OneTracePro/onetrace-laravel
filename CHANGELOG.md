# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.2.0 — 2026-10-07

- `onetrace.tracker.ignore_bots` (`ONETRACE_IGNORE_BOTS`) and the `ignore_bots` option of `@onetrace`: the tracker (1.4+) skips crawlers, link previews, monitoring services and automated browsers by default; false tracks everyone, e.g. for tests with Playwright or Selenium.

## 1.1.1 — 2026-10-07

Fixes for Laravel Octane (Swoole, RoadRunner, FrankenPHP), checked on a real Octane Swoole server:

- Events collected during a request are sent when that request terminates. Before, the after-response flush looked for them in the booted application instead of the request's sandbox, so they went out only when the sandbox was garbage-collected.
- The request (tracker cookie, IP, user agent, page URL) is read in Octane workers too: it was skipped in every CLI process.
- The tracker cookie is excluded from `EncryptCookies` (Laravel 11+), which turned it into null; Octane passes cookies only that way. On Laravel 10 add it to `$except` of your `EncryptCookies` middleware.

## 1.1.0 — 2026-10-07

- `OneTrace::useAnonymousId()` sets the visitor id for the rest of the request or job, including the automatic identify on login and registration: profiles are merged with the history before sign-in when the id does not come in the tracker cookie (mobile apps, SPAs, forms, queued jobs).
- `OneTrace::resolveAnonymousIdUsing()` for a custom source and the `anonymous_header` option (`ONETRACE_ANONYMOUS_HEADER`) for a request header.

## 1.0.0 — 2026-10-06

First release.

- Service provider with auto-discovery, `config/onetrace.php` from `.env`, the `OneTrace` facade with access to the whole API of `onetracepro/onetrace-php`.
- Events sent after the response, through the queue or immediately; the tracker's visitor id and the signed-in user are added automatically.
- `@onetrace` Blade directive with the tracker install code, linking the browser to the signed-in user and resetting it after logout; `identify` on Laravel's `Login` and `Registered` events.
- `SyncsWithOneTrace` trait for Eloquent product models and the `onetrace:sync-products` command.
- `OneTrace::fake()` with assertions for application tests.
- Laravel 10–13, PHP 8.1–8.5.
