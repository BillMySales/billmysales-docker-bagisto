<?php

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Settings of the Docker stack (registered in bootstrap/providers.php by
 * image/Dockerfile).
 *
 * Bagisto builds links from the request's Host header, and Caddy's ":80"
 * site answers any host: a password reset requested with a forged Host
 * mailed a valid reset link to that host. Only the host of APP_URL, the
 * ones in BAGISTO_EXTRA_HOSTS (comma separated) and loopback names (the
 * containers' healthchecks) are accepted (others get a 404), and URLs
 * always start with APP_URL.
 */
class StackServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $url = rtrim((string) config('app.url'), '/');

        if ($url === '') {
            return;
        }

        URL::forceRootUrl($url);
        URL::forceScheme((string) parse_url($url, PHP_URL_SCHEME));

        if ($this->app->runningInConsole()) {
            return;
        }

        $hosts = array_filter(array_map('trim', explode(',', (string) getenv('BAGISTO_EXTRA_HOSTS'))));
        $hosts[] = (string) parse_url($url, PHP_URL_HOST);
        $hosts[] = 'localhost';
        $hosts[] = '127.0.0.1';

        Request::setTrustedHosts(array_map(
            static fn (string $host): string => '^' . preg_quote($host) . '$',
            array_values(array_unique($hosts))
        ));
    }
}
