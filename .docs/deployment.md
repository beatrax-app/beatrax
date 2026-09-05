# Deploying Beatrax on a server

Beatrax ships primarily as a **local-only desktop app** (NativePHP). This guide
covers running it as a **self-hosted single-user/household web app** instead —
on your own machine or a private server you control. It is still meant to stay
private: host it on a LAN or behind a VPN/auth proxy, not on the public
internet.

Two paths are documented:

- **[Docker Compose](#a-docker-compose-recommended)** — one command, bundles
  the web process, queue worker and scheduler.
- **[Bare metal](#b-bare-metal-no-docker)** — clone + PHP + a SQLite file, no
  Docker.

Both finish with the interactive **`php artisan beatrax:setup`** command, which
writes `.env`, configures the database, and runs the install.

> **Read [Opening the loopback boundary](#opening-the-loopback-boundary) before
> you finish either path.** A fresh install answers on loopback only and refuses
> every other interface with `404` — including the ones your browser will use.
> Reaching it from another machine is one explicit setting, and nothing in the
> steps below sets it for you.

## Requirements

- PHP **8.5** with extensions: `intl`, `pcntl`, `posix`, `pdo_sqlite`,
  `zip`, `bcmath`, `zlib`, `iconv` — the last two are what the in-app PDF
  reader decompresses and transcodes statement streams with.
- `poppler-utils` (`pdftotext`) is **optional**. It is preferred for PDF
  statement ingestion where it is installed; without it the app falls back
  to a pure-PHP reader, which is what every phone build uses.
- Composer 2, and Node 22+ only if you build front-end assets yourself.
- **SQLite.** It is the only supported database, in every deployment shape.

> **Not PostgreSQL or MySQL, and not by omission.** The schema is SQLite-only:
> thirty-two migrations use `RAISE(ABORT)` enum-guard triggers, and full-text
> search is an FTS5 virtual table. `artisan migrate` against a server database
> fails on the first substantive table. See
> [ADR-0022](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0022-sqlite-only-schema.md).
>
> Full history is retained forever and never pruned — **back up that file**.

---

## A. Docker Compose (recommended)

The recipe lives in `deploy/server/`. It builds one app image (FrankenPHP +
PHP 8.5 + compiled assets, code baked in — no separate nginx/php-fpm) and runs
it three ways: web, queue worker, scheduler. The SQLite file lives on a named
volume, so it survives `down` and image rebuilds.

A one-shot `migrate` service runs first and the other three wait on it. Without
that ordering the workers boot against a schema that does not exist and
crash-loop until someone runs step 4.

```bash
COMPOSE="docker compose -f deploy/server/docker-compose.yml"

# 1. Configure the stack (this file is the single source of truth for all
#    app processes, so set the secrets here — not via beatrax:setup).
cp deploy/server/.env.example deploy/server/.env
#    edit deploy/server/.env: set APP_URL, and BEATRAX_SERVED_INTERFACES if
#    this install is reached from another machine

# 2. Generate an application key and paste it into deploy/server/.env as APP_KEY
$COMPOSE run --rm app php artisan key:generate --show

# 3. Build + start
$COMPOSE up -d --build

# 4. Create your account (migrations already ran in the `migrate` service)
$COMPOSE exec app php artisan beatrax:install

# 5. Confirm it is serving, not merely running
$COMPOSE ps                                              # app should read (healthy)
$COMPOSE exec app curl -fsS http://127.0.0.1/health
```

Non-interactively — which is what a script or a remote shell gets — pass the
account in, or the command refuses rather than prompting:

```bash
$COMPOSE exec app php artisan beatrax:install --no-interaction \
    --username=you --password='something-long'
```

> In Docker, set secrets in `deploy/server/.env` and use **`beatrax:install`**,
> not `beatrax:setup`. All three app containers read that one env file, so
> `APP_KEY` stays identical across web, queue, and scheduler.
> (`beatrax:setup` is for the single-process bare-metal path below.)

Step 5 is not ceremony. The `app` service carries a `healthcheck:` that fetches
`/health` **from inside the container** every 30 seconds, which is what makes
`docker compose ps` able to tell "the process is up" from "the application
answers". This recipe once returned 404 to every request while all four
containers were up and `migrate` had reported success, and nothing that watched
processes or ports could see it. The probe is run from inside because the
boundary is closed until you widen it, and a request arriving over the bridge
network is not a loopback one.

`deploy/server/smoke.sh` runs all of the above from a clean slate and checks
what comes back — the health contract, the same body twice, a real route that
is not a 404. CI runs it on any pull request touching this recipe, and again
before a release uploads anything.

Open `http://localhost:8000` (or your `APP_URL`). The queue worker and
scheduler already run as their own services.

`localhost` works out of the box; **your `APP_URL` does not until you name the
interfaces you serve on** — see [Opening the loopback
boundary](#opening-the-loopback-boundary). FrankenPHP publishes no bind address
at all, so on this path the boundary is decided by `APP_URL`'s host.

To update later:

```bash
git pull
docker compose -f deploy/server/docker-compose.yml up -d --build
docker compose -f deploy/server/docker-compose.yml exec app php artisan migrate --force
```

---

## B. Bare metal (no Docker)

For cloning straight onto a machine that already has PHP 8.5.

```bash
# 1. Clone + install PHP dependencies (production)
git clone <your-fork-or-this-repo> beatrax && cd beatrax
composer install --no-dev --optimize-autoloader

# 2. Build front-end assets (needs Node 22+); or copy a prebuilt public/build
npm ci && npm run build

# 3. Configure the environment + database + your account (interactive)
php artisan beatrax:setup
```

`beatrax:setup` walks you through:

1. creating `.env` (from `.env.example`) and generating `APP_KEY`;
2. pointing `DB_DATABASE` at the SQLite file and creating it;
3. verifying the database is reachable;
4. running `beatrax:install` (migrations + the single user account).

### Serving the app

- **Quick start:** `php artisan serve` (development-grade). It binds
  `127.0.0.1` by default and publishes no bind address, so reaching it from
  another machine needs both settings in [Opening the loopback
  boundary](#opening-the-loopback-boundary).
- **Production:** point nginx/Caddy at `public/` and run PHP-FPM. There is no
  vhost shipped in `deploy/server/` — the Docker recipe above uses FrankenPHP
  and needs none. Whatever you write, keep `fastcgi_param SERVER_ADDR
  $server_addr;` (nginx's stock `fastcgi_params` has it): that value is what
  `BEATRAX_SERVED_INTERFACES` is matched against, and a dispatcher that drops
  it leaves every request refused with not-found.

### Background workers

Beatrax uses a database queue + the Laravel scheduler. Run both as long-lived
processes (systemd units, supervisor, or `launchd` on macOS):

```bash
php artisan queue:work --tries=3 --backoff=60
php artisan schedule:work     # or a cron entry running `schedule:run` every minute
```

Example systemd unit (`/etc/systemd/system/beatrax-queue.service`):

```ini
[Unit]
Description=beatrax queue worker
After=network.target

[Service]
User=beatrax
WorkingDirectory=/opt/beatrax
ExecStart=/usr/bin/php artisan queue:work --tries=3 --backoff=60
Restart=always

[Install]
WantedBy=multi-user.target
```

---

## Opening the loopback boundary

**A fresh install answers on loopback only.** Every request that arrives on any
other interface is refused with `404`, by `LoopbackOnly` prepended as global
middleware — before routing, before auth, on every route including `/health`.
That is the default in all three deployment shapes and it does not change on its
own. Bring the Docker stack up without the setting below and every request from
another machine is a not-found, including the ones that look like the app is
simply broken.

Widening it takes one setting, and it names the interfaces you serve on:

```dotenv
# The bind addresses this install answers on, beyond loopback.
BEATRAX_SERVED_INTERFACES=192.168.1.50
APP_URL=https://beatrax.example.com
```

Both halves are load-bearing:

- **`BEATRAX_SERVED_INTERFACES`** is compared against the bind address the SAPI
  publishes (`SERVER_ADDR`) — nginx and php-fpm publish one. Only literal IP
  addresses are accepted, IPv4 or IPv6, comma-separated. A hostname, a CIDR
  range, and the wildcards `0.0.0.0` and `::` are each **ignored and reported**,
  never expanded: this setting is a list of interfaces, and it must not be able
  to spell "everything".
- **`APP_URL`** is what authorises a remote request under a runtime that
  publishes *no* bind address — which is what the shipped FrankenPHP image does,
  and what `php artisan serve` does. There the host the client asked for is the
  only evidence the request carries, so it must equal `APP_URL`'s host, and that
  host must be something other than `localhost`. `TrustedHostGuard` allow-lists
  the same host, so the two gates admit exactly one name.

Check what the install actually decided, without reading the config back:

```bash
php artisan beatrax:doctor          # names the interfaces taken and refused
curl -s http://127.0.0.1:8000/health
# {"status":"ok", ... ,"network_boundary":"widened"}
```

`network_boundary` is `loopback` or `widened` and nothing else. It never lists
the interfaces: once the boundary is open that response crosses the network, and
an inventory of the other interfaces served would be a disclosure rather than a
diagnosis. The interface list is in `beatrax:doctor`, where the caller already
holds a shell on the machine.

### What is still in front of the app once it is open

Widening moves the app from "unreachable" to "reachable and asking who you are".
What remains is the sign-in, the app-lock PIN, and `TrustedHostGuard`. What does
**not** appear is transport security: the shipped image serves plain HTTP on
port 80 and Beatrax terminates no TLS of its own. On an open network that means
the password and every rendered balance travel in clear. Put it behind a VPN, a
Tailscale/WireGuard address, or a reverse proxy holding a certificate — and
prefer naming *that* private address in `BEATRAX_SERVED_INTERFACES` over a LAN
address the whole subnet can reach.

---

## Logging, queue, and dev tools

- **Logging.** The Docker recipe sets `LOG_CHANNEL=stderr`, so application logs
  go to the container's output — view them with
  `docker compose -f deploy/server/docker-compose.yml logs -f app`. On bare
  metal the default `daily` channel writes rotating files under
  `storage/logs/`; set `LOG_CHANNEL=stderr` instead if a process manager
  captures stdout. Set `LOG_LEVEL=info` (or `warning`) in production.
- **Queue.** Beatrax uses the **database** queue driver — no Redis. The Docker
  stack runs a dedicated `queue` service (`php artisan queue:work`); on bare
  metal run that as a systemd/supervisor process (see above). The scheduler
  (`schedule:work`) likewise runs as its own process/service.
- **Horizon.** Laravel Horizon is a **dev-only** dependency, used solely by the
  in-app Dev Console to inspect the queue. `composer install --no-dev` omits it
  and `bootstrap/providers.php` drops its service provider automatically, so it
  never loads on a server. Running Horizon as a queue manager would require
  Redis and is intentionally out of scope.
- **Dev Console / dev tools.** The Dev Console is gated behind
  `BEATRAX_DEV_MODE` (default `false`) — keep it off in production. There are no
  Telescope/Debugbar dependencies. The `migrate`/`install` paths and the
  community-corpus seeders all run identically on a server.

## What a self-hosted install does not get: biometric unlock

**Biometric / WebAuthn unlock enrolment is refused on a self-hosted web
deployment, deliberately.** The three `/lock/biometric/*` routes answer `403` and the
Enrol button in app-lock settings surfaces a localised explanation instead of doing
nothing. PIN unlock is the supported path here. Unlocking an *existing* credential is
not gated — in practice a self-hosted install can never have one.

The reason is where the key would have to live. Enrolment stores
`secret (32 bytes) || wrapped_key_bytes` in
`user_biometric_credentials.biometric_wrap_secret`: the unwrapping key and the wrapped
app-lock data key, concatenated, in the same SQLite file as the transactions. On the
desktop bundle that blob is protected by the operating system's key store through
Electron's `safeStorage`. A server has no such store, so the app binds the pass-through
shield — the identity function — and anyone holding the database file would hold the
app-lock data key, and therefore the group data keys and the counterparty blind-index
key, with no PIN, password, biometric, or Argon2 in the way.

The gate reads the shield's own `protectsAtRest()` capability rather than testing for a
platform, so it is a property of what the installation can actually do. See [which
columns are encrypted at
rest](features/sync/sensitive-columns-at-rest.md#where-the-key-lives-on-a-phone-and-the-one-condition-that-argument-rests-on).

## Configuration reference

`beatrax:setup` writes these; you can also set them by hand in `.env`:

| Key | Purpose |
|-----|---------|
| `APP_URL` | Public URL the app is served at. Its host is also what a widened install accepts as a `Host` where the runtime publishes no bind address |
| `BEATRAX_SERVED_INTERFACES` | Comma-separated literal IP addresses this install serves on beyond loopback. Empty (the default) refuses every non-loopback request with not-found |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` on a server |
| `DB_CONNECTION` | `sqlite` — the only supported value, in every deployment shape |
| `DB_DATABASE` | Path to the SQLite file. Defaults to the per-user data directory; the Docker recipe points it at the `app-data` volume |
| `QUEUE_CONNECTION` | `database` (default; no Redis required) |

The connection is defined in `config/database.php`, which names no engine other
than SQLite. The `pgsql`, `mysql` and `mariadb` options this guide used to offer
are gone: they described something that could not work, and
[ADR-0022](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0022-sqlite-only-schema.md)
withdrew them.
