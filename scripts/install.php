<?php

/**
 * First install, after the migrations (run by setup.sh on an empty
 * database): the basic data of Bagisto's installer (DatabaseSeeder: locales,
 * currencies, countries, channel, attributes, categories, CMS pages...) in
 * APP_LOCALE and APP_CURRENCY, then the admin user (BAGISTO_ADMIN_*), like
 * `bagisto:install` without its database wipe and its default password.
 */

require __DIR__ . '/bootstrap.php';

use Webkul\Installer\Helpers\DatabaseManager;

$locale = getenv('APP_LOCALE') ?: 'es';
$currency = getenv('APP_CURRENCY') ?: 'CLP';
$manager = app(DatabaseManager::class);

// The helpers report errors to the log (stderr) and return false.
$manager->seed([
    'default_locale' => $locale,
    'allowed_locales' => [$locale],
    'default_currency' => $currency,
    'allowed_currencies' => [$currency],
    'skip_admin_creation' => true,
]) || fail('Seeding Bagisto\'s basic data failed (see the log above)');
echo "Basic data seeded ({$locale}, {$currency})\n";

$manager->createAdminUser([
    'name' => getenv('BAGISTO_ADMIN_NAME') ?: 'Admin',
    'email' => getenv('ADMIN_MAIL_ADDRESS'),
    'password' => getenv('BAGISTO_ADMIN_PASSWORD'),
]) || fail('Creating the admin user failed (see the log above)');
echo 'Admin user created: ' . getenv('ADMIN_MAIL_ADDRESS') . "\n";
