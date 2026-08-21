<p align="center">
  <img src="resources/brand/logo.svg" width="200" alt="Beatrax">
</p>

<p align="center">
  <em>A local-first personal finance dashboard for the unified picture of your cross-account money.</em>
</p>

<p align="center">
  <a href="https://github.com/beatrax-app/beatrax/actions/workflows/ci.yml"><img alt="ci" src="https://github.com/beatrax-app/beatrax/actions/workflows/ci.yml/badge.svg"></a>
  <a href="https://github.com/beatrax-app/beatrax/actions/workflows/shared.yml"><img alt="shared checks" src="https://github.com/beatrax-app/beatrax/actions/workflows/shared.yml/badge.svg"></a>
  <a href="https://sonarcloud.io/summary/new_code?id=beatrax-app_beatrax"><img alt="quality gate" src="https://sonarcloud.io/api/project_badges/measure?project=beatrax-app_beatrax&metric=alert_status"></a>
  <a href="https://sonarcloud.io/project/issues?id=beatrax-app_beatrax&resolved=false"><img alt="sonar issues" src="https://img.shields.io/sonar/violations/beatrax-app_beatrax?server=https%3A%2F%2Fsonarcloud.io&label=sonar%20issues"></a>
  <a href="https://sonarcloud.io/component_measures?id=beatrax-app_beatrax&metric=coverage"><img alt="coverage" src="https://img.shields.io/sonar/coverage/beatrax-app_beatrax?server=https%3A%2F%2Fsonarcloud.io&label=coverage"></a>
  <a href="https://scorecard.dev/viewer/?uri=github.com/beatrax-app/beatrax"><img alt="OpenSSF Scorecard" src="https://api.scorecard.dev/projects/github.com/beatrax-app/beatrax/badge"></a>
  <img alt="License: Hippocratic 3.0" src="https://img.shields.io/badge/license-Hippocratic--3.0-blue.svg">
  <img alt="PHP 8.5+" src="https://img.shields.io/badge/php-8.5%2B-777bb4.svg">
  <img alt="Laravel 13" src="https://img.shields.io/badge/laravel-13.x-ff2d20.svg">
  <img alt="Status: v1.3 shipped · v2.0 in development" src="https://img.shields.io/badge/v1.3%20shipped%20%C2%B7%20v2.0%20in%20development-brightgreen.svg">
</p>

<p align="center">
  <a href="https://beatrax.app">Website</a> ·
  <a href="https://github.com/beatrax-app/spec">Specification</a> ·
  <a href="https://discord.gg/FYuV9CbTHR">Discord</a>
</p>

## What is Beatrax?

Beatrax is a local-first personal finance dashboard that pulls together
transactions from your bank, credit cards, PayPal, and app-store
subscriptions into a single calm "this month at a glance" view. It reads
the statement formats European banks already export — CAMT.053 (ISO
20022), MT940, and CSV — so it is not tied to any one institution. It
then resolves the routing chains between your accounts (PayPal → your
bank or card, card → bank via bulk SEPA settlement) so that fixed monthly
payments, real underlying funding sources, and upcoming cash flow are
visible in one place instead of buried across statements.

It runs entirely on your own machine. No telemetry. No cloud sync. No
remote account. The SQLite database, the OAuth tokens, the cached
email receipts — all live on your disk and never leave it unless you
choose to export them yourself.

The product is **source-available**, not open-source in the OSI sense.
The full source is here for you to read, run, and modify; the license
adds ethical-use clauses on top of that. See [NOTICE.md](NOTICE.md) for
the longer explanation.

## Who is this for?

Beatrax is built for a single person — or a two-person household —
managing their own finances across multiple bank, card, and
payment-processor accounts who wants to see their monthly position in
one place instead of cobbling together statements every cycle. It
assumes you are technically literate enough to install a desktop
application, grant OAuth permission to your Gmail or Microsoft Graph
inbox if you want email-receipt scanning, and read a CSV or PDF when
you need to.

If you bank exclusively with one institution that already gives you a
great app, you probably don't need Beatrax. If you split your spending
across several banks, cards, PayPal, and recurring app-store
subscriptions and have given up on reconciling them by hand, this is for
you.

## Thanks, mom

Thanks to my mom — Bea, for anyone who wondered where the name came
from — who was the inspiration for making this.

## Install

Beatrax ships installers for macOS, Windows, and Linux. Pick the one
for your platform.

### Installing on macOS

Beatrax is an independent app. macOS will warn you the first time you
open it — that's expected.

1. Open the downloaded **beatrax.dmg** and drag Beatrax into your
   Applications folder.
2. Right-click **Beatrax** in Applications and choose **Open**.
3. When macOS asks "are you sure?", click **Open**.
4. From now on, double-clicking Beatrax launches it normally.

**Alternative (Terminal one-liner):**

```sh
xattr -d com.apple.quarantine /Applications/beatrax.app
```

> Like most independent macOS apps, Beatrax isn't signed with an Apple
> Developer ID — we don't pay Apple $99/year just to avoid the
> first-launch dialog. [Why we made this choice →](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md#no-paid-signing)

#### Intel Macs (x86_64)

The prebuilt installer ships an **Apple Silicon (arm64) DMG only**.
GitHub's hosted macOS runners are all Apple Silicon now, and building
an Intel bundle there under Rosetta 2 emulation routinely overruns the
job timeout. Until that changes, Intel Mac users build from source:

```sh
git clone git@github.com:beatrax-app/beatrax.git
cd beatrax
composer install
npm ci
cp .env.example .env
php artisan key:generate
php artisan native:install --publish --no-interaction --force
php artisan native:build mac x64
# Installer lands at nativephp/electron/dist/beatrax-<version>-x64.dmg
```

Full local-dev prerequisites (Docker, Node 22+, PHP 8.5) are in
[.docs/local_development/setup.md](.docs/local_development/setup.md).

### Installing on Windows

Beatrax is an independent app. Windows SmartScreen will warn you the
first time you open it — that's expected.

1. Run the downloaded **beatrax-setup.exe**.
2. When you see "Windows protected your PC", click **More info**.
3. Click **Run anyway**.
4. From now on, Beatrax launches normally from the Start menu.

> SmartScreen reputation builds up over time as more people open
> Beatrax. After a few weeks, the warning will stop appearing for new
> users automatically. [Why we made this choice →](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md#no-paid-signing)

### Installing on Linux

Beatrax ships as both an AppImage (portable) and a .deb (Debian /
Ubuntu native).

**AppImage:**

```sh
chmod +x beatrax-*.AppImage
./beatrax-*.AppImage
```

**.deb (Debian / Ubuntu / Mint):**

```sh
sudo dpkg -i beatrax-*.deb
```

### Verifying the download

Every release publishes SHA-256 checksums and an Ed25519-signed
manifest. If you'd like to verify integrity:

```sh
sha256sum beatrax-{version}-{platform}.{ext}
```

Then compare against the checksum file published with the release. For
the deeper "is this manifest authentic?" check, see
[the verification runbook →](.docs/runbooks/verify-release.md).

## Screenshots

A full walkthrough of every surface — the setup wizard end to end, the
dashboard, transactions, envelope budgets, the cash-flow forecast,
counterparties and funding chains, drift and anomaly alerts, tax, goals
and pots — lives on the website, alongside short recordings of the
multi-step flows:

**[beatrax.app](https://beatrax.app)**

Contributors capturing fresh screenshots can populate a representative
demo dataset first with `php artisan demo:seed --reset`.

## Project status

**v1.3.0 "Local & in sync"** is the current release (14 June 2026) — FX
conversion, savings goals and pots, an installable PWA, PIN and biometric
app-lock, the bills calendar, tax tagging with per-year export, full-text
search, and anomaly alerts.

**v2.0** is staged and is what this repository holds today — its locked
goals are in the
[version manifest](https://github.com/beatrax-app/spec/blob/main/70-operations/versions/2.0.0.toml):
local-first end-to-end-encrypted peer-to-peer device sync, a proactive
notification inbox, an optional open-banking connector, envelope
(zero-based) budgeting, split transactions, account reconciliation, a
general rules engine, migration importers for YNAB and Actual, and a
custom report builder. Wiring the mobile client as a fully synced peer and
app-store distribution are the remaining pieces.

See the releases page on GitHub for the full history and the latest
download.

## Contributing

This project's specification is **canonical**: every change cites an
identifier that already exists in it, and a behavioural change's spec
pull request merges first. Before your first PR, read
[CONTRIBUTING.md](CONTRIBUTING.md), [AGENTS.md](AGENTS.md) if you are
working with an AI assistant, and the
[contributing guide](https://github.com/beatrax-app/spec/blob/main/50-governance/contributing.md).

Implementation detail — which class, which file, which table — lives in
[`.docs/`](.docs/00-index.md). Behaviour, requirements, and architecture
contracts live in the [spec](https://github.com/beatrax-app/spec). Where
the two disagree, the spec wins.

## License + ethics

Beatrax is licensed under the [Hippocratic License 3.0](LICENSE). It's
source-available, not OSI-approved — see [NOTICE.md](NOTICE.md) for the
rationale and the
[longer rationale](https://github.com/beatrax-app/spec/blob/main/90-appendix/license-rationale.md)
in the spec.

## Security

Report vulnerabilities via [Security Policy](SECURITY.md).

---

<p align="center">
  <a href="https://nightworks.io">NightWorks.io</a>
  &nbsp;&middot;&nbsp;
  <a href="https://discord.gg/FYuV9CbTHR">Discord</a>
</p>
