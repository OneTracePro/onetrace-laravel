<?php

declare(strict_types=1);

namespace OneTrace\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/**
 * The website tracker snippet for the @onetrace Blade directive: loads cdp.js with the write key, links the browser
 * to the signed-in user (by id only, no personal data in the page) and resets the visitor after logout.
 */
class Tracker
{
    /** Cookie set on logout so that the next page resets the tracker. */
    public const RESET_COOKIE = 'onetrace_reset';

    public function __construct(protected Application $app)
    {
    }

    /**
     * @param array{nonce?: string|null, page?: bool} $options nonce: CSP nonce (Vite's nonce by default);
     *                                                       page: record the page view (true by default)
     */
    public function render(array $options = []): HtmlString
    {
        $config = $this->app->make('config');
        $key = $config->get('onetrace.write_key');

        if (!$config->get('onetrace.enabled', true) || !\is_string($key) || $key === '') {
            return new HtmlString('');
        }

        $host = (string) preg_replace('#/api/v1/?$#', '', rtrim((string) $config->get('onetrace.url'), '/'));
        $lines = [
            "!function(w,d,u){var c=w.cdp=w.cdp||[];if(c.version)return;['init','page','track','identify','alias','reset','widgets','renderRecommendations'].forEach(function(m){c[m]=c[m]||function(){c.push([m].concat([].slice.call(arguments)))}});var s=d.createElement('script');s.async=1;s.src=u;d.head.appendChild(s)}(window,document," . self::js($host . '/tracker/cdp.js') . ');',
            'cdp.init(' . self::js($key) . ', { host: ' . self::js($host) . ' });',
        ];

        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        if ($config->get('onetrace.tracker.reset_on_logout', true) && $request instanceof Request && $request->cookies->has(self::RESET_COOKIE)) {
            $lines[] = 'cdp.reset();';
            $this->app->make(CookieJar::class)->queue($this->app->make(CookieJar::class)->forget(self::RESET_COOKIE));
        }

        $userId = $config->get('onetrace.tracker.identify', true) ? $this->userId() : null;

        if ($userId !== null) {
            // identify once per browser and user: the tracker remembers the user id in localStorage.
            $id = self::js($userId);
            $lines[] = "try{if(localStorage.getItem('cdp_uid')!=={$id})cdp.identify({$id})}catch(e){cdp.identify({$id})}";
        }

        if ($options['page'] ?? true) {
            $lines[] = 'cdp.page();';
        }

        $nonce = \array_key_exists('nonce', $options) ? $options['nonce'] : $this->viteNonce();
        $attribute = \is_string($nonce) && $nonce !== '' ? ' nonce="' . e($nonce) . '"' : '';

        return new HtmlString("<script{$attribute}>\n" . implode("\n", $lines) . "\n</script>");
    }

    protected function userId(): ?string
    {
        if (!$this->app->bound('auth')) {
            return null;
        }

        $id = $this->app->make('auth')->id();

        return \is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    protected function viteNonce(): ?string
    {
        $vite = 'Illuminate\Foundation\Vite';

        if (!class_exists($vite) || !$this->app->bound($vite)) {
            return null;
        }

        $nonce = $this->app->make($vite)->cspNonce();

        return \is_string($nonce) ? $nonce : null;
    }

    /**
     * A JavaScript string literal that is safe inside an HTML <script> element.
     */
    private static function js(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
