<p align="center">
  <img src="resources/brand/logo.svg" width="200" alt="beatrax">
</p>

<p align="center">
  <em>A local-first personal finance dashboard for the unified picture of your cross-account money.</em>
</p>

<p align="center">
  <img alt="License: Hippocratic 3.0" src="https://img.shields.io/badge/license-Hippocratic--3.0-blue.svg">
  <img alt="PHP 8.4+" src="https://img.shields.io/badge/php-8.4%2B-777bb4.svg">
  <img alt="Laravel 13" src="https://img.shields.io/badge/laravel-13.x-ff2d20.svg">
  <img alt="Status: pre-1.0" src="https://img.shields.io/badge/status-v0.x-orange.svg">
</p>

## What is beatrax?

beatrax is a local-only personal finance dashboard that pulls together
transactions from ASN Bank, ICS Cards, PayPal, and Google Play into a
single calm "this month at a glance" view. It resolves the routing
chains between these accounts (PayPal → ASN or ICS, ICS → ASN via bulk
iDEAL settlement) so that fixed monthly payments, real underlying
funding sources, and upcoming cash flow are visible in one place
instead of buried across statements.

It runs entirely on your own machine. No telemetry. No cloud sync. No
remote account. The SQLite database, the OAuth tokens, the cached
email receipts — all live on your disk and never leave it unless you
choose to export them yourself.

The product is **source-available**, not open-source in the OSI sense.
The full source is here for you to read, run, and modify; the license
adds ethical-use clauses on top of that. See [NOTICE.md](NOTICE.md) for
the longer explanation.

## Who is this for?

beatrax is built for a single person — or a two-person household —
managing their own finances across multiple Dutch bank, card, and
payment-processor accounts who wants to see their monthly position in
one place instead of cobbling together statements every cycle. It
assumes you are technically literate enough to install a desktop
application, grant OAuth permission to your Gmail or Microsoft Graph
inbox if you want email-receipt scanning, and read a CSV or PDF when
you need to.

If you bank exclusively with one institution that already gives you a
great app, you probably don't need beatrax. If you split your spending
across ASN + ICS + PayPal + recurring Google Play subscriptions and
have given up on reconciling them by hand, this is for you.

## Install

beatrax ships installers for macOS, Windows, and Linux. Pick the one
for your platform.

### Installing on macOS

beatrax is an independent app. macOS will warn you the first time you
open it — that's expected.

1. Open the downloaded **beatrax.dmg** and drag beatrax into your
   Applications folder.
2. Right-click **beatrax** in Applications and choose **Open**.
3. When macOS asks "are you sure?", click **Open**.
4. From now on, double-clicking beatrax launches it normally.

**Alternative (Terminal one-liner):**

```sh
xattr -d com.apple.quarantine /Applications/beatrax.app
```

> Like most independent macOS apps, beatrax isn't signed with an Apple
> Developer ID — we don't pay Apple $99/year just to avoid the
> first-launch dialog. [Why we made this choice →](.docs/legal/license-rationale.md#no-paid-signing)

### Installing on Windows

beatrax is an independent app. Windows SmartScreen will warn you the
first time you open it — that's expected.

1. Run the downloaded **beatrax-setup.exe**.
2. When you see "Windows protected your PC", click **More info**.
3. Click **Run anyway**.
4. From now on, beatrax launches normally from the Start menu.

> SmartScreen reputation builds up over time as more people open
> beatrax. After a few weeks, the warning will stop appearing for new
> users automatically. [Why we made this choice →](.docs/legal/license-rationale.md#no-paid-signing)

### Installing on Linux

beatrax ships as both an AppImage (portable) and a .deb (Debian /
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

A handful of representative screens from the running app:

- **Dashboard** — the "this month at a glance" view with the cash-flow
  forecast and the per-category breakdown.
  <!-- screenshot: .docs/assets/screenshots/dashboard.png -->
- **Chains review** — the page that shows PayPal → ASN and ICS → ASN
  funding-chain resolutions, so you can see what really paid for what.
  <!-- screenshot: .docs/assets/screenshots/chains.png -->
- **Forecast** — 30 / 60 / 90-day cash-flow projection with what-if
  scenarios.
  <!-- screenshot: .docs/assets/screenshots/forecast.png -->
- **Dev Console** — the diagnostic panel for inspecting ingestion
  state, queue health, and the SQLite WAL.
  <!-- screenshot: .docs/assets/screenshots/dev-console.png -->
- **Multi-user profile selector** — switch between personal and
  shared-household views without leaving the app.
  <!-- screenshot: .docs/assets/screenshots/multi-user.png -->

Contributors capturing fresh screenshots can populate a representative
demo dataset with `php artisan demo:seed --reset` once that command
lands in an upcoming release.

## Project status

beatrax is in active development on the v0.x line. The shape and
behaviour visible in this repository today is the work that the v1.0.0
release will bundle. See the release page on GitHub for the latest
download.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License + ethics

beatrax is licensed under the [Hippocratic License 3.0](LICENSE). It's
source-available, not OSI-approved — see [NOTICE.md](NOTICE.md) for the
rationale.

## Security

Report vulnerabilities via [Security Policy](SECURITY.md).
