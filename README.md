Bagisto Docker stack
====================

Docker Compose stack for [Bagisto](https://bagisto.com) (open source
e-commerce on Laravel: shop, admin panel, customers, orders, invoices,
shipments, taxes, promotions), usable for local development and for simple
production deployments (a single server). Maintained by
[BillMySales](https://www.billmysales.com).

| Component   | Image                                        | Default version        |
|-------------|----------------------------------------------|------------------------|
| Web server  | `caddy:<ver>-alpine`                         | 2.11                   |
| Bagisto     | own image (`image/`) on `php:<ver>-fpm-alpine` | 2.4.12 (Laravel 12, PHP 8.4) |
| Database    | `mariadb:<ver>`                              | 12.3 (LTS)             |
| Mailpit     | `axllent/mailpit` (optional, dev)            | v1.31                  |

The vendor images (`webkul/bagisto`) are all-in-one (Ubuntu, supervisord
with nginx, PHP-FPM and the database in one container, installed at build
time with fixed credentials), so `image/Dockerfile` builds one (~1.5
minutes, ~430 MB, amd64 and arm64; tested on arm64):

- the release's source (GitHub tag archive, SHA-256 verified:
  `BAGISTO_SHA256`) with its own `composer.lock`, installed without dev
  packages; theme assets are prebuilt upstream (no Node build);
- `php:8.4-fpm-alpine` (Bagisto 2.4 supports PHP 8.3–8.4) with the
  required extensions (bcmath calendar exif gd intl pdo_mysql zip) and
  `icu-data-full` (Alpine's ICU only has English locale data);
- `image/overlay`: a small service provider (see [Security](#security)).

MariaDB 12.3 (the current LTS): Bagisto's installer offers MySQL and MariaDB,
and its production image bundles either.

Requirements
------------

- Docker Engine 24+ with the Compose v2 plugin (`docker compose`, 2.20+).
- About 1 GB of disk for the images; 512 MB of RAM for the stack.
- Development: ports 8118, 8418 and 8025 free on the host.
- Production: a server with ports 80 and 443 reachable, and a DNS record for
  the site's domain pointing to it.

Quick start (development)
-------------------------

```shell
cp .env.dev.example .env
docker compose up -d --build   # builds the image the first time (~1.5 minutes)
docker compose logs -f setup   # wait for "==> Done"
```

- Shop: http://localhost:8118
- Admin: http://localhost:8118/admin (user `admin@example.com`, password
  `admin12345`).
- Mailpit (every email Bagisto sends): http://localhost:8025

Production
----------

```shell
cp .env.prod.example .env
# Required: BAGISTO_URL, BAGISTO_ADMIN_PATH, SITE_ADDRESS, APP_KEY,
# DB_PASSWORD, DB_ROOT_PASSWORD, BAGISTO_ADMIN_EMAIL, BAGISTO_ADMIN_PASSWORD.
# Recommended: the SMTP_* values (without SMTP_HOST no emails are sent).
docker compose up -d --build
```

- With `SITE_ADDRESS` set to the domain, Caddy gets a Let's Encrypt certificate
  and renews it automatically (certificates live in the `caddy_data` volume).
- Behind another TLS-terminating proxy, use `SITE_ADDRESS=:80`.
- Compose refuses to start while a required value is missing.
- The admin panel lives at `<url>/<BAGISTO_ADMIN_PATH>` (required: pick
  something other than `admin`).
- The `backup` profile is enabled by default in the production template.
- Behind an existing Traefik (no host ports), use `overrides/traefik.yaml`
  (see [Overrides](#overrides)).
- The image is built on the server (or build it elsewhere, push it to a
  registry and set `BAGISTO_IMAGE`).

Services
--------

| Service     | Profile   | Role                                                              |
|-------------|-----------|-------------------------------------------------------------------|
| `db`        |           | MariaDB, data in the `db_data` volume.                            |
| `setup`     |           | One-shot job (`scripts/setup.sh`), runs on every `up`.            |
| `php`       |           | PHP-FPM with Bagisto (internal port 9000).                        |
| `worker`    |           | Laravel queue worker (database queue): emails, imports, indexing, admin notifications. |
| `scheduler` |           | Laravel scheduler (`schedule:work`): price and catalog rule indexes, invoice reminders, exchange rates, campaigns. |
| `caddy`     |           | TLS, static files, the only published ports (80, 443).            |
| `console`   | `tools`   | Artisan, as `www-data`.                                           |
| `backup`    | `backup`  | Database dump + storage on a schedule.                            |
| `mailpit`   | `mailpit` | Development SMTP server that catches all mail.                    |

The code lives in the image (immutable). Caddy serves the static files from
the `public` volume (a copy of the image's `public/`, refreshed by `setup`
when the image changes) and the uploads from the `storage` volume
(`public/storage` is a symlink into it); everything else goes to
`index.php` (PHP-FPM), including resized product images (`/cache/...`).
Every container builds Laravel's config, route and event caches from its
own environment when it starts (`scripts/run.sh`).

### What `setup` does

- Copies the image's `public/` to Caddy's volume when the image's build id
  changes (also after a same-version rebuild).
- Empty database: migrations, then the basic data of Bagisto's installer
  (its `DatabaseSeeder`: locale, currency, countries, channel, attributes,
  categories, CMS pages, theme) in `BAGISTO_LOCALE` and `BAGISTO_CURRENCY`,
  then the admin user (`BAGISTO_ADMIN_*`; `scripts/install.php`). Bagisto's
  own `bagisto:install` is **not** used: it wipes the database
  (`db:wipe` + `migrate:fresh`) and creates `admin@example.com`/`admin123`.
  A database with tables but no Bagisto admin is refused (nothing is
  changed): a failed first install needs `docker compose down -v`.
- Installed: pending migrations (a new version).
- `scripts/configure.php`: every run, the channel's URL (`BAGISTO_URL`; on
  a change, also in the theme's footer links, which the installer stores as
  absolute URLs). Once (then kept as edited in the admin):
  - store name as the channel's name and home title;
  - CLP formatted as `$9.990` (Settings > Currencies: symbol on the left,
    `.` for thousands, no decimals; Bagisto's default formats with the
    locale, and `es` is Spain's `9.990,00 $`);
  - the inventory source and shipping origin in Chile (Santiago);
  - tax category and rate "IVA" 19% for Chile, the default for products and
    shipping, prices including tax and displayed with it;
  - a free "Despacho" shipping method (the $10-per-unit flat rate off);
  - only "Transferencia bancaria" (money transfer) as payment method: cash
    on delivery, PayPal, Stripe, Razorpay, PayU, PhonePe and PayGlocal come
    active without credentials;
  - social login buttons off (no provider configured).
- Clears Laravel's caches and the full page cache, and compiles the views.

Common commands
---------------

```shell
docker compose ps                        # status: every service "healthy", setup "Exited (0)"
docker compose logs -f php worker        # logs (Laravel logs to stderr)
docker compose exec db mariadb -ubagisto -p bagisto   # SQL shell
docker compose run --rm console          # artisan about (profile "tools")
docker compose run --rm console indexer:index --mode=full
docker compose run --rm console responsecache:clear   # full page cache
docker compose down                      # stop, keep data
docker compose down -v                   # stop and DELETE all data
```

Store and language
------------------

- The shop and the admin are in Spanish (Bagisto's `es` translation); the
  installer's CMS pages, categories and theme sections are created in it.
  Its demo theme content ("Tienda de Demostración" banners, images) is part
  of the basic data: edit it in Appearance > Sections.
- Prices are decimals (`9990` CLP is shown as `$9.990`); with IVA included
  in prices, Bagisto computes the tax inside them (IVA $3.190 in 2 ×
  $9.990).
- Chile has no regions in Bagisto's country data: the checkout's region is
  a free text field.
- Guest checkout is enabled; customers can also register.
- No product is created: add them in the admin (Catalog > Products).
- Bagisto caches full pages for guests (`Bagisto-FPC`): after changing
  content directly in the database, run `responsecache:clear`.

Emails
------

Bagisto sends them through the queue (`worker`): order, invoice, shipment,
refund and cancellation emails (customer and admin), account emails,
password resets. SMTP comes from `SMTP_*`; settings saved in the admin
(Configuration > Emails) take precedence. `SMTP_SECURE=ssl` = SMTPS; any
other value: STARTTLS when the server offers it (Bagisto's mailer has no
"never TLS" mode). Without `SMTP_HOST`, emails are discarded. The sender and
the contact address are `SMTP_FROM` / `SMTP_FROM_NAME`; admin notifications
go to `BAGISTO_ADMIN_EMAIL`.

Backups
-------

With the `backup` profile, the `backup` service writes `<timestamp>-db.sql.gz`
and `<timestamp>-files.tar.gz` (the storage volume without Laravel's caches:
uploads, theme images, imports, invoices) to the `backups` volume (or
`./data/backups` with `overrides/local-dirs.yaml`) at start and then every
`BACKUP_INTERVAL_HOURS`, and deletes files older than `BACKUP_KEEP_DAYS`.
Files are readable by their owner only. `APP_KEY` is not in them: keep your
`.env`.

```shell
docker compose run --rm --no-deps backup now                  # back up now
docker compose run --rm --no-deps backup list                 # list timestamps
docker compose stop php worker scheduler                      # stop the app first
docker compose run --rm --no-deps backup restore <timestamp>  # database and storage
docker compose up -d                                          # setup clears the caches
```

`--no-deps` keeps the command from starting `setup` first (with damaged
data `setup` fails and the restore would never run); the database must
be running (`docker compose up -d db` if the stack is down).

A restore drops every table first, so nothing created after the backup
remains.

Upgrades
--------

Back up first, read Bagisto's `UPGRADE.md` for a new minor version, then
change `BAGISTO_VERSION` (and `BAGISTO_SHA256`) in `.env` and run
`docker compose up -d --build`: the image is rebuilt and `setup` runs the
migrations and refreshes the public files before PHP-FPM starts. Rebuild
regularly (`docker compose build --pull`) for PHP and Alpine security fixes.

- 2.3 → 2.4 renames the theme customizations to sections (migrations keep
  the data) and removes visitor tracking: its `visits` table stays in
  upgraded databases (Bagisto's guide: dropping it is optional).
- Bagisto 2.5 (in beta) needs Laravel 13 and PHP 8.4+.

Customizing the image
---------------------

The image is Bagisto's repository at a release tag. Bagisto packages
(extensions, themes, a payment method) are Laravel packages: add them in
`image/Dockerfile` (`composer require` before the install, or copy them to
`packages/` with an overlay and register their provider in
`bootstrap/providers.php`), then `docker compose up -d --build`. There is no
plugin mount override: a package needs Composer's autoloader and a
registered provider, which a mounted directory doesn't get. A BillMySales
integration would be a Bagisto package listening to Bagisto's events
(`checkout.order.save.after`, `sales.invoice.save.after`) or a client of
the REST/GraphQL API package (`bagisto/bagisto-api`).

Overrides
---------

Optional compose files in `overrides/`, enabled with `COMPOSE_FILE` in `.env`
(several are combined with `:`). Each file documents its variables.

```shell
COMPOSE_FILE=compose.yaml:overrides/traefik.yaml:overrides/local-dirs.yaml
```

| File                        | Purpose                                                            |
|-----------------------------|--------------------------------------------------------------------|
| `overrides/traefik.yaml`    | Publish through an existing Traefik on a shared external network:  |
|                             | no host ports, Traefik terminates TLS (`TRAEFIK_HOST`, ...).       |
| `overrides/local-dirs.yaml` | Database, public files, storage, Caddy and backups in local        |
|                             | directories (`DATA_DIR`, default `./data`) instead of volumes.     |

A local `compose.override.yaml` (gitignored) is also loaded automatically by
Docker Compose, for changes specific to one machine.

Configuration
-------------

Every variable is documented in `.env.prod.example`. Main groups:

- **Site and network**: `BAGISTO_URL`, `BAGISTO_EXTRA_HOSTS`,
  `BAGISTO_ADMIN_PATH`, `SITE_ADDRESS`, `HTTP_BIND`, `HTTP_PORT`,
  `HTTPS_PORT`, `TIMEZONE`.
- **Credentials**: `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`,
  `BAGISTO_ADMIN_EMAIL`, `BAGISTO_ADMIN_PASSWORD` (required),
  `BAGISTO_ADMIN_NAME`.
- **Store** (Laravel's `APP_NAME`, `APP_LOCALE`, `APP_CURRENCY`, read on
  every start: set them before the first install and don't change the locale
  or the currency afterwards, the database must have them):
  `BAGISTO_STORE_NAME` (also the channel's name on the first install),
  `BAGISTO_LOCALE` (default locale), `BAGISTO_CURRENCY` (base currency).
- **Store** (applied once): `BAGISTO_COUNTRY`, `BAGISTO_CITY`,
  `BAGISTO_TAX_*`, `BAGISTO_PRICES_INCLUDE_TAX`.
- **Versions**: `BAGISTO_VERSION`, `BAGISTO_SHA256`, `PHP_VERSION`,
  `MARIADB_VERSION`, `CADDY_VERSION`, ...
- **Mail**: `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`,
  `SMTP_PASSWORD`, `SMTP_FROM`, `SMTP_FROM_NAME`.
- **PHP, resources and logs**: `LOG_LEVEL`, `APP_DEBUG`, `PHP_*`,
  `PHP_FPM_*`, `*_MEMORY_LIMIT` per service, `UPLOAD_MAX_SIZE`,
  `LOG_MAX_SIZE`, `LOG_MAX_FILE`.

Notes:

- Production settings: `APP_ENV=production`, debug off, PHP errors logged
  (never displayed unless `PHP_DISPLAY_ERRORS=On`), Laravel logs to stderr
  (`docker compose logs`) instead of files, OPcache without file checks,
  sessions and queue in the database, cache in files (storage volume).
- Laravel trusts `X-Forwarded-*` from any peer (Bagisto's
  `trustProxies(at: '*')`): only Caddy reaches PHP-FPM, and it passes the
  real client IP and scheme, so links and secure cookies follow the public
  `https://` address behind Caddy and Traefik.
- Bagisto answers an invalid CSRF token (and a refused host) with "500 Error
  interno del servidor" (JSON or HTML), not 419 or 404. Shop pages carry the
  token in `_token` form inputs, not in a `csrf-token` meta tag.
- From inside the containers, the host machine is reachable as
  `host.docker.internal`.

Security
--------

- **Host header**: Bagisto built links from the request's `Host` (and
  Caddy's `:80` site answers any host): a password reset requested with a
  forged `Host` mailed a valid reset link to that host. The stack's service
  provider (`image/overlay/app/Providers/StackServiceProvider.php`) only
  accepts the host of `BAGISTO_URL`, `BAGISTO_EXTRA_HOSTS` and loopback
  names (healthchecks), and builds every URL from `BAGISTO_URL`. With
  `SITE_ADDRESS` set to a domain or behind Traefik, other hosts never reach
  PHP anyway.
- Client IP headers: PHP gets only the real client IP (as Caddy sees it) in
  `REMOTE_ADDR`, `X-Forwarded-For` and `X-Real-IP`, and no `Client-Ip`,
  `Cf-Connecting-Ip` or `X-Forwarded-Port` (a client could forge them), like
  in the other PHP stacks. Sessions record that IP.
- No default secrets: compose fails if the required passwords, the app key
  and the admin path are missing. The development template uses public
  values; never use it on a server.
- PHP runs as `www-data`; only Caddy (and Mailpit in development) publishes
  ports; PHP-FPM and MariaDB are internal. `HTTP_BIND` defaults to
  `127.0.0.1`.
- Caddy only runs `index.php`, blocks dotfiles and the web installer
  (`/install`; `setup` installs), and serves nothing else from the project
  (code, `.env`, storage outside `public/storage`). Bagisto adds its own
  security headers; PHP's version is not exposed.
- Not included: a web application firewall or off-site backup copies.

Validation
----------

What was checked for this stack (2026-09-25):

- Clean start (`down -v` + `up -d`, image built) in about 30 s: every
  service `healthy`, `setup` `Exited (0)`; a second run makes no changes and
  keeps admin changes (store name, tax rate, payment title, currency
  format).
- Shop and admin (login form with CSRF, dashboard, orders) in Spanish with
  all their CSS, JS and images, including theme images and a product image
  resized through `/cache/medium/...`.
- A full guest checkout through the shop's API: 2 × $9.990 = $19.980 with
  IVA $3.190 included, free "Despacho", bank transfer as the only payment
  method; order and admin emails in Mailpit in Spanish with links to
  `BAGISTO_URL`; queue empty, no failed jobs.
- A password reset requested with a forged `Host` is refused (before the
  fix, the email carried a valid reset link to that host).
- Backup and restore (deleted orders, renamed store, a dropped table and
  deleted theme images back; a table created after the backup gone).
- Upgrade 2.3.19 (Laravel 11, PHP 8.3) → 2.4.12 (Laravel 12, PHP 8.4) with
  an order: the 2.4 migrations applied, data and theme images kept, a new checkout and
  the admin afterwards; the schema matches a fresh install except for the
  old `visits` table.
- `BAGISTO_URL` change and back (channel URL and footer links rewritten).
- HTTPS with `SITE_ADDRESS=localhost` and the production template (links,
  custom admin path, blocked paths, no error details); overrides: Traefik
  v3.6 with no host ports (a public client IP, 8.8.8.8, in the sessions;
  `https` links, `Secure` session cookie, admin at a custom path), local
  directories (fresh install, backup).
- Not tested: issuing a real Let's Encrypt certificate (needs a public
  domain), SMTPS/STARTTLS with a real provider, the online payment methods.

Testing
-------

- Guest checkout through the shop's API, with a web session (cookies of a
  shop page) and its `_token` in `X-CSRF-TOKEN`: `POST /api/checkout/cart`
  (`product_id`, `quantity`), `/api/checkout/onepage/addresses` (`billing`,
  `shipping`; `phone` required, no region list for Chile),
  `/api/checkout/onepage/shipping-methods` (`shipping_method: free_free`),
  `/api/checkout/onepage/payment-methods`
  (`payment: {method: moneytransfer}`), then
  `POST /api/checkout/onepage/orders`.
- Products from code: `ProductRepository` `create`, then `update` with the
  channel, locale, inventories and `tax_category_id`; then
  `indexer:index`.

Resource usage
--------------

Idle, after a few requests: MariaDB ~120 MiB, PHP-FPM ~65 MiB, worker
~60 MiB, scheduler ~55 MiB, Caddy ~15 MiB (about 320 MiB in total). Image
~430 MB.

License
-------

[MIT](LICENSE).
