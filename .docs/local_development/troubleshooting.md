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

The CI matrix runs both 8.4 and 8.5, so code that requires 8.5-only constructs (`array
unpacking with string keys`, etc.) breaks the 8.4 axis even if it runs locally on 8.5.
When in doubt, run `composer require --dev php:^8.4` in a throwaway branch and re-run
the suite — it surfaces accidental 8.5-only syntax in seconds.

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

## NativePHP build fails — no 8.5 binary

`nativephp/php-bin` currently ships pre-compiled binaries for PHP 8.1, 8.2, 8.3, and
8.4 only. There is no 8.5 binary available yet. The shipped bundle is therefore built
against 8.4, even though the developer machine runs 8.5.

If `php artisan native:build` fails with a message about a missing binary for the
detected PHP version, the project's `composer.json` `php` constraint has likely been
narrowed away from `^8.4`. Confirm:

```sh
jq -r '.require.php' composer.json
# Expected: ^8.4   (note: NOT ^8.5)
```

The two-PHP setup is deliberate: dev box runs 8.5 for the most recent language and
tooling support, shipped bundle runs 8.4 because that is what NativePHP has binaries
for, and CI runs both axes so neither path silently regresses.

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
