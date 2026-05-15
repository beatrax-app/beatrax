# diederik

A local-only personal finance dashboard that pulls together transactions from ASN
Bank, ICS Cards, PayPal, and Google Play into a single calm "this month at a
glance" view.

The app resolves the routing chains between these accounts (PayPal → ASN or ICS,
ICS → ASN via bulk iDEAL settlement) so that fixed monthly payments, real
underlying funding sources, and upcoming cash flow are visible in one place
instead of buried across statements.

## Prerequisites

- PHP 8.5
- Composer 2.x
- SQLite 3.45+
- Node.js 20+ (for the Vite asset pipeline)
- macOS recommended; Laravel Herd ships a compatible PHP binary out of the box
- Poppler (`pdftotext` binary) for ICS PDF statement parsing — `brew install poppler` on macOS; verify with `pdftotext -v`. See https://poppler.freedesktop.org/ for source builds on other platforms.

## Install

```sh
composer install
pdftotext -v          # confirms poppler is on PATH (required for ICS PDF imports — see Prerequisites)
php artisan diederik:install   # ships in Plan 02
```

## Serving

- Laravel Herd: open `https://diederik.test` after running `herd link`
- Or, ad-hoc: `php artisan serve --host=127.0.0.1 --port=8080`

The app is bound to loopback only. Production cloud deployment is out of scope
(PLT-01).

## Backups

Plain `cp database.sqlite` is unsafe in WAL mode — the `.sqlite-wal` and
`.sqlite-shm` sidecar files contain uncommitted pages. A `php artisan db:backup`
command using `VACUUM INTO` ships in a later phase (FND-05); until then, stop
the app before any manual backup.

## CI gates

The three checks must pass on every change:

```sh
vendor/bin/pest                # tests
vendor/bin/phpstan analyse     # static analysis at level max
vendor/bin/pint --test         # code style
```

## Module layout

The codebase lives under `Modules/` with five bounded contexts: `Core`,
`Ledger`, `Ingestion`, `Import`, `Categorization`. Each module exposes a
`Public/` namespace for cross-module callers; everything under `Internal/`
is private. A custom PHPStan rule (`App\PhpStan\Rules\BoundaryRule`) enforces
the boundary in CI.

Constructor dependency injection is the only allowed style. Global helpers
(`auth()`, `config()`, `now()`, …) and facades (`Auth::`, `DB::`, …) are
banned in non-test, non-Routes, non-Migrations code.
