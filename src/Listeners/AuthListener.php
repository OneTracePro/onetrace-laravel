<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Listeners;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Cookie\CookieJar;
use OneTrace\Laravel\OneTrace;
use OneTrace\Laravel\Tracker;

/**
 * identify() on login and registration; after logout the next page resets the tracker (see Tracker).
 * Dependencies are resolved at call time, so the listener is safe in long-running workers (Octane).
 */
class AuthListener
{
    public function login(Login $event): void
    {
        if ($this->config()->get('onetrace.identify.on_login', true)) {
            Container::getInstance()->make(OneTrace::class)->identify($event->user);
        }
    }

    public function registered(Registered $event): void
    {
        if ($this->config()->get('onetrace.identify.on_register', true)) {
            Container::getInstance()->make(OneTrace::class)->identify($event->user);
        }
    }

    public function logout(Logout $event): void
    {
        $config = $this->config();

        // A cookie, not the session: logout usually invalidates the session right after this event.
        if ($config->get('onetrace.tracker.reset_on_logout', true) && $config->get('onetrace.write_key') && Container::getInstance()->bound(CookieJar::class)) {
            Container::getInstance()->make(CookieJar::class)->queue(Tracker::RESET_COOKIE, '1', 5);
        }
    }

    private function config(): Repository
    {
        return Container::getInstance()->make('config');
    }
}
