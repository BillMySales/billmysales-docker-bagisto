<?php

/**
 * Boots Bagisto's Laravel application for the stack's PHP scripts
 * (install.php, configure.php).
 *
 * With the HTTP kernel, not the console one: the console kernel also loads
 * routes/console.php, whose schedule needs a channel (Bagisto 2.3 failed
 * there on a migrated but not yet seeded database). Any exception ends the
 * script with exit code 1: Laravel's own handler prints it and exits with 0,
 * which made a failed install look successful.
 */

chdir('/var/www/bagisto');
require '/var/www/bagisto/vendor/autoload.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$app = require '/var/www/bagisto/bootstrap/app.php';
// The HTTP kernel expects a request (URLs are built from APP_URL anyway).
$app->instance('request', Illuminate\Http\Request::create(getenv('APP_URL') ?: 'http://localhost'));

try {
    $app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
} catch (Throwable $e) {
    fail('Bagisto failed to start: ' . $e->getMessage());
}

// Replaces Laravel's handler (registered by the bootstrap).
set_exception_handler(static function (Throwable $e): void {
    fail(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
});
