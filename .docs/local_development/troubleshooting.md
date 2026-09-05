# Troubleshooting

Recurring gotchas that catch new contributors and the existing maintainer alike. Listed
in the order they tend to bite.

## "PHP version mismatch" when running tests

The project is pinned to PHP 8.5 for development. If `composer install` or `artisan
test` complains about a version constraint, the toolchain is most likely resolving an
older PHP than the Docker image targets.

```sh
# Confirm what the Docker image resolves to
docker compose run --rm php php --version

# Should report 8.5.x
```

If it reports 8.4 or 8.3, the image is stale — rebuild it with `docker compose build
--no-cache php` so it picks up the pinned `php:8.5-cli` base.

There is only one PHP version in play. `composer.json` requires `"php": "^8.5"`, the
Docker image targets 8.5, and CI runs a single `php: ['8.5']` axis — three test shards
of it plus a static-analysis job, and nothing on 8.4. So a constraint complaint is a
stale image or a host PHP older than the project, never a second axis you have to keep
happy. Rebuilding the image is the fix; narrowing the `php` constraint in
`composer.json` is not, and 8.5-only syntax is free to use.

## "Class 'Sodium' not found" / Ed25519 verification fails

The auto-update path verifies signed manifests via libsodium's bundled functions. PHP
8.4+ ships libsodium in the core distribution, and the Docker image installs it
explicitly. Check:

```sh
docker compose run --rm php php -m | grep -i sodium
# Expected: sodium
```

If the extension is missing, the image is stale or the extension install step in
`docker/php8.5/Dockerfile` was edited — rebuild with `docker compose build --no-cache
php`.

## NativePHP build fails on a missing PHP binary

`php artisan native:build` does not compile PHP. It copies a pre-built static binary out
of `nativephp/php-bin`, and it chooses which one from the version of PHP running the
build: NativePHP exports `NATIVEPHP_PHP_BINARY_VERSION` as
`PHP_MAJOR_VERSION.PHP_MINOR_VERSION`, and the extraction step then looks for
`php-<that>.zip`. So a missing-binary failure always has the same cause — the machine is
on a PHP minor the package carries no binary for.

Check what is actually in the package before assuming anything:

```sh
ls vendor/nativephp/php-bin/bin/mac/arm64/
# php-8.3.zip  php-8.4.zip  php-8.5.zip
```

`mac/x64`, `win/x64`, `linux/x64` and `linux/arm64` carry the same three. 8.5 is one of
them, so the version this project requires is also the version the shipped bundle is
built against — there is no two-PHP split to keep in your head. Run `php --version` and
line the shell up with the project; do not reach for `composer.json` and narrow the
`php` constraint, which adds no binary and breaks the pin the rest of the toolchain
runs on.

## "Database is locked" during a long-running command

Almost always a stale lock from a prior process — a queue worker or scheduler that was
killed mid-write. Two recovery paths:

```sh
# 1. Identify the holder.
lsof database/database.sqlite

# 2. Stop the long-running command via standard signal handling first
#    (Ctrl-C or `kill -TERM <pid>`), only escalating to SIGKILL after a
#    clean shutdown has failed.
```

For the deeper case where Laravel's `withoutOverlapping` lock outlives a crashed
schedule run, see `Stuck withoutOverlapping lock` in
[`../runbooks/operator-recovery.md`](../runbooks/operator-recovery.md).

## Vite "could not resolve entry module"

Almost always a stale `node_modules` or a Vite cache that survived a Node major-version
bump:

```sh
rm -rf node_modules .vite
npm ci
npm run dev
```

If the error persists after a clean install, check that the most recent Tailwind v4
config rules are present at the project root (`vite.config.js` references the Tailwind
oxide plugin) — a botched git merge can leave that file pointing at the v3 plugin.

## OAuth callback rejected by Google / Microsoft

The OAuth dance uses the RFC 8252 loopback IP scheme
(`http://127.0.0.1:PORT/oauth/callback/{provider}`) rather than any `*.test`
host, because both providers reject `*.test` subdomains as redirect URIs.

If a freshly-registered OAuth client rejects the callback, the registered redirect URI
likely still names `https://beatrax.test/...`. Re-register the URI as
`http://127.0.0.1:8000/oauth/callback/google` (or `/microsoft`) in the provider's
console; the port matches `app.url` and falls back to `8000` if the value is not set.

## Tests pass locally but fail in CI

Run the suite the same way CI does:

```sh
APP_ENV=testing php artisan test --parallel
```

The `APP_ENV=testing` prefix forces the test database (in-memory SQLite by default) and
disables any developer overrides in `.env`. If a test then fails locally too, the
real cause is a state leak in the developer's `.env` rather than a CI quirk.

## `composer test` hangs and never prints a summary

Cap the workers, or run the suite the way CI does.

`composer test` is `pest --parallel --processes=4`. Without the cap, paratest
defaults to the core count, and at eight workers the full suite deadlocks: the
worker processes stay alive, output stops, and nothing is ever reported. It is
not a slow run — it does not finish. The same suite completes in about eight
minutes at four.

This is the reason `phpstan.neon` pins `maximumNumberOfProcesses` as well; both
tools fan out over the same tree and both became unreliable above four workers
on this codebase.

CI never hits it, because it shards the suite across three runners and each
shard fans out over a fraction of the tests. That is also the fastest way to
reproduce a CI failure locally:

```sh
APP_ENV=testing php artisan test --parallel \
    --testsuite="$(python3 .github/scripts/shard-testsuites.py --shard 1 --of 3)"
```
