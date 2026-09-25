<?php

/**
 * Store settings (run by setup.sh on every `up`; safe to repeat).
 *
 * Every run: the channel's hostname = APP_URL (Bagisto stores the install's),
 * and on a change the old URL is replaced in the theme sections too (the
 * installer stores absolute footer links: /page/about-us...).
 *
 * Once (marker `docker_stack.initialized` in core_config, written last, so a
 * failed run is repeated; later changes in the admin are kept):
 * - store name (BAGISTO_STORE_NAME) as the channel's name and home title;
 * - the currency's format (Settings > Currencies): Bagisto's default
 *   formats with APP_LOCALE, and `es` is Spain's "9.990,00 $"; CLP gets the
 *   symbol on the left, "." for thousands and no decimals ("$9.990");
 * - the default inventory source and the shipping origin in the country
 *   (BAGISTO_COUNTRY, CL);
 * - tax: category and rate (BAGISTO_TAX_NAME, BAGISTO_TAX_RATE %) for the
 *   country, default for products and shipping, prices including tax
 *   (BAGISTO_PRICES_INCLUDE_TAX) and displayed with it;
 * - shipping: free "Despacho" (flat rate, $10 per unit by default, off);
 * - payment: only "Transferencia bancaria" (money transfer); the others
 *   (cash on delivery, PayPal, Stripe, Razorpay, PayU, PhonePe, PayGlocal)
 *   come active without credentials;
 * - social login buttons off (no provider is configured).
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Support\Facades\DB;
use Webkul\Core\Repositories\CoreConfigRepository;

$env = static fn (string $name, string $default = ''): string => (getenv($name) === false || getenv($name) === '') ? $default : getenv($name);

$url = rtrim($env('APP_URL'), '/');
$old = rtrim((string) DB::table('channels')->where('code', 'default')->value('hostname'), '/');
if ($old !== $url) {
    DB::transaction(function () use ($old, $url) {
        DB::table('channels')->where('code', 'default')->update(['hostname' => $url]);
        if ($old === '') {
            return;
        }
        // In the JSON options, as is and with escaped slashes.
        $replace = [$old => $url, str_replace('/', '\\/', $old) => str_replace('/', '\\/', $url)];
        foreach (DB::table('theme_section_translations')->get() as $row) {
            $changes = [];
            foreach (['options', 'draft_options'] as $column) {
                if ($row->{$column} !== null && ($new = strtr($row->{$column}, $replace)) !== $row->{$column}) {
                    $changes[$column] = $new;
                }
            }
            if ($changes) {
                DB::table('theme_section_translations')->where('id', $row->id)->update($changes);
            }
        }
    });
    echo "Channel URL: {$url}" . ($old !== '' ? " (was {$old})" : '') . "\n";
}

if (DB::table('core_config')->where('code', 'docker_stack.initialized')->exists()) {
    echo "Store settings OK (initial settings already applied)\n";
    exit;
}

$locale = $env('APP_LOCALE', 'es');
$name = $env('APP_NAME', 'Bagisto');
$country = strtoupper($env('BAGISTO_COUNTRY', 'CL'));
$city = $env('BAGISTO_CITY', 'Santiago');
$email = $env('MAIL_FROM_ADDRESS', $env('ADMIN_MAIL_ADDRESS'));
$inclusive = $env('BAGISTO_PRICES_INCLUDE_TAX', 'true') === 'true' ? 'including_tax' : 'excluding_tax';
$channel = DB::table('channels')->where('code', 'default')->first();

DB::transaction(function () use ($locale, $name, $country, $city, $email, $inclusive, $channel, $env) {
    DB::table('channel_translations')->where('channel_id', $channel->id)->update([
        'name' => $name,
        'home_seo' => json_encode(['meta_title' => $name, 'meta_description' => '', 'meta_keywords' => '']),
    ]);

    if ($env('APP_CURRENCY', 'CLP') === 'CLP') {
        DB::table('currencies')->where('code', 'CLP')->update([
            'symbol' => '$',
            'decimal' => 0,
            'currency_position' => 'left',
            'group_separator' => '.',
            'decimal_separator' => ',',
        ]);
    }

    DB::table('inventory_sources')->where('code', 'default')->update([
        'contact_name' => $name,
        'contact_email' => $email,
        'contact_number' => '',
        'country' => $country,
        'state' => '',
        'city' => $city,
        'street' => '',
        'postcode' => '',
    ]);

    // Configuration values as the admin's configuration form saves them.
    $config = [
        'sales' => [
            'shipping' => [
                'origin' => [
                    'country' => $country,
                    'city' => $city,
                    'store_name' => $name,
                ],
            ],
            'carriers' => [
                'free' => ['active' => 1, 'title' => 'Despacho', 'description' => 'Despacho a domicilio'],
                'flatrate' => ['active' => 0],
            ],
            'payment_methods' => [
                'moneytransfer' => [
                    'active' => 1,
                    'title' => 'Transferencia bancaria',
                    'description' => 'Te enviaremos los datos de la cuenta por correo; despacharemos tu pedido cuando recibamos el pago.',
                ],
            ],
        ],
        'customer' => [
            'settings' => [
                'social_login' => [],
            ],
        ],
    ];
    foreach (['cashondelivery', 'paypal_smart_button', 'paypal_standard', 'stripe', 'razorpay', 'payu', 'phonepe', 'payglocal'] as $method) {
        $config['sales']['payment_methods'][$method] = ['active' => 0];
    }
    foreach (['facebook', 'twitter', 'google', 'linkedin', 'github'] as $provider) {
        $config['customer']['settings']['social_login']["enable_{$provider}"] = 0;
    }

    $rate = $env('BAGISTO_TAX_RATE');
    if ($rate !== '') {
        $taxName = $env('BAGISTO_TAX_NAME', 'IVA');
        $code = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $taxName));
        $rateId = DB::table('tax_rates')->insertGetId([
            // Shown as "<identifier> (<rate>%)" in carts and orders.
            'identifier' => $taxName,
            'is_zip' => 0,
            'zip_code' => '*',
            'state' => '',
            'country' => $country,
            'tax_rate' => (float) $rate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $categoryId = DB::table('tax_categories')->insertGetId([
            'code' => $code,
            'name' => $taxName,
            'description' => "{$taxName} {$rate}%",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tax_categories_tax_rates')->insert([
            'tax_category_id' => $categoryId,
            'tax_rate_id' => $rateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $display = ['display_prices' => $inclusive, 'display_subtotal' => $inclusive, 'display_shipping_amount' => $inclusive];
        $config['sales']['taxes'] = [
            'categories' => ['product' => $categoryId, 'shipping' => $categoryId],
            'calculation' => ['product_prices' => $inclusive, 'shipping_prices' => $inclusive],
            'default_destination_calculation' => ['country' => $country],
            'shopping_cart' => $display,
            'sales' => $display,
        ];
        echo "Tax {$taxName} {$rate}% ({$country})\n";
    }

    app(CoreConfigRepository::class)->create($config + ['channel' => $channel->code, 'locale' => $locale]);

    DB::table('core_config')->insert([
        'code' => 'docker_stack.initialized',
        'value' => '1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

echo "Initial store settings applied ({$country})\n";
