# Contributing to beatrax

## Welcome

beatrax is a small, local-only personal-finance dashboard built in
Laravel and Livewire. Contributions are welcome — bug fixes,
performance work, additional ingestion adapters, additional
categorization rules, additional email-receipt parsers, and (when
internationalization lands) translations. The project ships under the
[Hippocratic License 3.0](LICENSE), a source-available license with
ethical-use clauses; contributors agree both to the license and to the
[Code of Conduct](CODE_OF_CONDUCT.md) by participating.

If you're not sure whether something is a good fit, open a Discussion
before sinking effort into a pull request. We'd rather agree on shape
early than ask you to rework a finished PR.

## Setup

Detailed local setup lives in [`.docs/local_development/setup.md`](.docs/local_development/setup.md).
The short version:

- Install [Docker](https://docs.docker.com/get-docker/); the PHP 8.5
  toolchain is defined in `docker-compose.yml` and `docker/php8.5/Dockerfile`.
- Clone the repo into your project directory.
- `docker compose run --rm php composer install`
- `npm ci`
- `docker compose run --rm php php artisan migrate`
- Run the dev server (`docker compose run --rm php php artisan serve`).

The project ships with sample fixtures so you can exercise the
ingestion paths without owning the source banks' accounts.

## Code conventions

These are non-negotiable; the CI gate enforces them on every pull
request.

### Constructor dependency injection only

All module code uses constructor dependency injection. No facade calls
(`Auth::user()`, `DB::table(...)`, `Cache::get(...)`), no global helpers
(`auth()`, `config(...)`, `request(...)`, `now()`, `app()`) inside the
`Modules/` tree. Eloquent models are the exception — direct use is fine
because the models themselves are the constructor-injected
collaborators most of the time. Facades and helpers stay confined to
Laravel's `routes/web.php` (the static `Route` DSL), service-provider
registration, and migrations.

### Modular boundary

Code lives in one of:

- `Modules/<Name>/Public/` — cross-module entry points (contracts,
  service classes, events, queryable models). Anything here may be
  consumed by other modules.
- `Modules/<Name>/Internal/` — module-private implementation. Other
  modules must not reach in.

Cross-module access happens through the Public namespace by constructor
injection, or through the event bus when reactivity is required. An
architecture test in `tests/Contracts/BoundaryArchTest.php` walks the
namespace graph and fails the build on any violation; the same test
file enforces the DI rule above.

### Quality gate

Every pull request must pass three gates:

```sh
vendor/bin/pint --test                     # formatting
vendor/bin/phpstan analyse --memory-limit=1G   # Larastan level 10 strict
vendor/bin/pest                            # unit + feature + arch tests
```

Run them through the Docker toolchain, e.g.
`docker compose run --rm php vendor/bin/pint --test`. The full test
suite runs with `docker compose run --rm php php artisan test`
(serial, like CI) or `composer test` (Pest under `--parallel`, faster
local iteration). The CI workflow runs all three gates on PHP 8.5.

> **Docker caveat:** a handful of `Modules/DevMode` tests spawn real
> `php artisan` child processes (the spawn-then-tail console). Those
> children behave inconsistently under Docker Desktop's bind-mounted
> filesystem and may fail locally even though they pass in CI's native
> PHP. If your only failures are in `DevMode\CommandSpawnerTest`,
> `ArtisanCancelTest`, or `ArtisanStreamReconnectTest`, they are this
> known environment limitation, not a regression.

Frontend tests are not required — the UI is server-rendered Livewire +
Blade, and test investment goes into backend correctness.

## Branch and commit conventions

- Branch names follow `<type>/<short-slug>` where `<type>` is `feat`,
  `fix`, `chore`, `docs`, `refactor`, `test`, or `perf`. Example:
  `feat/counterparty-profile-tabs`.
- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):
  `<type>(<scope>): <subject>`. Example: `fix(ledger): handle empty
  description on CAMT.053 import`.
- The `main` branch requires signed commits. Configure
  `git config commit.gpgsign true` (with a key registered on your
  GitHub account) before you push.
- Linear history is required on `main` — no merge commits. Pull
  requests are merged via squash or rebase.

## Pull request flow

1. Open the pull request against `main`.
2. Fill in the PR template. CODEOWNERS is auto-configured and will
   request the right reviewer.
3. Write the commit subject as a conventional commit (`feat:`, `fix:`,
   `docs:`, `refactor:`, `perf:`, `test:`, `ci:`, `build:`, `chore:`). Release
   notes are generated from the commit log at tag time, so the subject is what
   a reader will see. Mark a breaking change with `!` after the type, or a
   `BREAKING CHANGE:` trailer.
4. Wait for CI to go green. The PHP 8.5 quality gate (Pint, Larastan
   level 10, Pest) must pass before review starts.
5. Address review feedback inline; push fixups as new commits (no
   force-push during review unless the reviewer asks for it).
6. Once approved, the reviewer (or you, if self-merge is permitted)
   squash-merges. The head branch is auto-deleted after merge.

## What's in scope for v1.x contributions

In scope:

- Bug fixes in any module.
- Performance improvements with a benchmark or profiling justification.
- Additional bank / card / payment-processor ingestion adapters
  (especially Dutch-market sources we haven't covered yet).
- Additional categorization rules and merchant-resolution heuristics.
- Additional email-receipt parsers.
- Documentation improvements, especially worked examples for the
  `.docs/features/` per-module pages.
- Accessibility improvements on existing UI surfaces.

Not in scope at v1.0:

- Features explicitly listed as "Out of Scope" in the project's roadmap
  documents (telemetry, partner-sharing modes, cloud sync, mobile
  clients).
- Switching the database away from SQLite, switching the frontend stack
  away from Livewire, or other foundational rework. These would be
  whole-architecture conversations, not pull requests.
- Anything that requires the app to phone home — beatrax is local-only
  by design, and that constraint won't move.

If you have an idea that sits in the grey zone, open a Discussion
first. It's faster than writing code that won't merge.
