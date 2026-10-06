# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.0.0 — 2026-10-06

First release.

- Service provider with auto-discovery, `config/onetrace.php` from `.env`, the `OneTrace` facade with access to the whole API of `onetracepro/onetrace-php`.
- Events sent after the response, through the queue or immediately; the tracker's visitor id and the signed-in user are added automatically.
- `@onetrace` Blade directive with the tracker install code, linking the browser to the signed-in user and resetting it after logout; `identify` on Laravel's `Login` and `Registered` events.
- `SyncsWithOneTrace` trait for Eloquent product models and the `onetrace:sync-products` command.
- `OneTrace::fake()` with assertions for application tests.
- Laravel 10–13, PHP 8.1–8.5.
