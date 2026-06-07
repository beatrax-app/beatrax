# Changelog

All notable changes to this project are documented in this file.

This file is the **single source of truth** for release notes. The GitHub Release
body for each tag is generated from the matching section below (see
`scripts/changelog-section.php` and the release workflow) — edit the changelog,
not the release on GitHub.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `CHANGELOG.md` as the source of truth for release notes, automatically
  published into each GitHub Release body by the release workflow.
- **Month-over-month spending** — the dashboard shows a "This month vs last"
  card: the current period's total spend with the signed change against the
  previous period, and the categories that moved the most (each with its delta).
  Spending more reads in rose, less in emerald. Uses the same EUR-settled outflow
  definition as the rest of the ledger, so the figures reconcile; renders nothing
  until there is a prior period to compare.
- **Sidebar count badges** — the nav items (Transactions, Recurring,
  Counterparties, Drift alerts, Budgets, Subscriptions, Imports, Receipts) now
  show how many items each holds. All counts come from a single per-user cached
  payload (NavCountsService, short TTL, invalidated on demand) so the sidebar —
  which renders on every page — never fans out a pile of COUNT queries per
  render. Large counts are compact-formatted (e.g. "3.1k"), and a count of zero
  hides the badge to keep the rail calm.
- **"You could save here" insights** — the dashboard shows a "Ways to save"
  card that pairs your recurring subscriptions with the most relevant official
  link from the support-resource corpus: a cheaper / student / retention plan
  where one exists, a cancellation page when a subscription's price has drifted
  up, or a gentle review nudge for an ongoing charge. Each suggestion is
  dismissible (and stays dismissed), and the whole set is cached per user so the
  dashboard never re-runs the resolution on every render. Purely informational —
  beatrax surfaces the official link, it never cancels or switches anything.
- **Recurring ↔ counterparty navigation** — a counterparty profile's Recurring
  card now lists that merchant's recurring series (each linking to the series
  detail), and the recurring series detail page links back to the counterparty
  profile. Resolved through the shared `transactions.counterparty_id`, so it is
  exact rather than name-matched.
- **Settings redesign** — the Settings page is reorganised from a narrow flat
  list into wider, titled cards (Appearance · Preferences · Forecasting ·
  Importing · Shared merchant list · Data & backup · Help · Developer), keeping
  every existing control and behaviour, with proper padding from the nav.
- **Encrypted backups** — Settings → Data & backup can now download a
  passphrase-encrypted snapshot of your whole database (a `VACUUM INTO` copy,
  encrypted in place and streamed as a `.sqlite.enc` file), safe to keep on an
  external drive or in cloud storage because it is unreadable without the
  passphrase. Encryption is quantum-safe by construction: a purely symmetric
  scheme (no public-key step, so no Shor exposure) using Argon2id key derivation
  and XChaCha20-Poly1305 with a 256-bit key. The plaintext snapshot is locked to
  0600 and deleted as soon as it is encrypted; the passphrase never persists.
  Available on the SQLite (desktop) build.
- **Support-resource profiles** — a counterparty profile now shows a "Support &
  cancelling" card (merchants) or "Getting help" card (government) with the
  official cancel / help / cheaper-plan links, a `tel:` helpline, and — where a
  service genuinely supports it — a one-click pre-filled cancellation `mailto:`.
  Backed by a new bundled `resources/corpus/support/<country>.yaml` corpus
  (researched for ~25 common subscriptions and the main NL agencies; the
  cancellation method, e.g. online form / phone / registered letter, is shown in
  a note since none of these document email cancellation). Looked up by
  word-level brand matching that tolerates legal-entity suffixes ("Netflix
  International BV" → Netflix) without false-matching ("Apple" never matches
  "Applebee's"; a plain "Albert Heijn" grocery charge never inherits the
  "Albert Heijn Premium" cancel card). Link schemes are restricted to http(s)
  and the mailto address is injection-guarded.
- **Subscription Drift Watch** — a new `/drift/watch` page ("Subscriptions" in
  the sidebar) that ranks your approved subscriptions by how much their price
  has crept up since the first charge, each with a baseline → latest figure, a
  signed €/% delta, an amount-history sparkline, and an "open alert" badge that
  deep-links to the drift alert. The subscription-centric companion to the
  alert-centric `/drift` page; reuses the recurring occurrence history rather
  than adding a new store, and reads the full history so the baseline is the
  true first charge.
- **Category Budgets** — a new `/budgets` page (Budgets module) to set a
  monthly spending ceiling per expense category and track the current period's
  spend against it, with a status-coloured progress bar (under / near / over),
  remaining amount, an inline editor, and a period total. Budget writes are
  validated against the user's own + global expense categories (a
  client-supplied category id can never attach a budget to another user's
  category), and the amount field accepts both plain and Dutch grouped formats.
  An optional **Budgets step** is added to the first-run setup wizard so new
  users can set a few category budgets during onboarding (or skip it); a step
  added after a user already finished onboarding is seeded as skipped, so
  finished users are not dropped back into the wizard.

### Fixed

- The receipt-conflict resolver ("Use receipt") no longer 500s on a malformed
  stored value: a non-JSON `incoming_value` is skipped (not applied) while the
  pending row is still cleared, matching the read side's tolerance. The demo
  seeder now JSON-encodes the conflict values like the production producer.
- Server-deployment support alongside the NativePHP desktop build. `config`
  now defines Postgres, MySQL, and MariaDB connections (SQLite stays the
  default, so the desktop build is unchanged), selectable via `DB_CONNECTION`
  + the `DB_*` env vars. A new interactive `php artisan beatrax:setup` command
  walks a self-hoster through writing `.env` (app URL, application key,
  database), verifies the connection, and hands off to `beatrax:install` in a
  fresh process. A `deploy/server/` recipe ships a single-container FrankenPHP
  image (code + assets baked in) with Postgres, a queue worker, and the
  scheduler, and `.docs/deployment.md` documents both the Docker and the
  bare-metal (clone-without-Docker) paths, including logging to stderr, the
  database queue (no Redis/Horizon on a server), and keeping the Dev Console
  off in production.
- Import other European banks' CSV exports via a preset-driven generic CSV
  importer. Bundled presets cover N26, Revolut, and ING (Netherlands), each
  selectable under a new "Other bank" source in the import wizard. The engine
  handles the cross-bank differences — signed vs separate debit/credit columns
  vs an "Af/Bij"-style direction indicator, comma-or-dot decimals with thousands
  separators, per-row or fixed currency, and varied date formats — matches
  columns by a normalised header name (tolerant of minor spelling differences),
  skips pending/reverted rows (e.g. Revolut `State`) instead of aborting, and
  rounds sub-cent amounts rather than truncating. Adding another bank is a
  data-only change (a new entry in `CsvPresetRegistry`).

### Added

- Government-agency and bank-fee classification now lives in a regex-capable,
  per-country YAML corpus (`resources/corpus/<type>/<country>.yaml`) instead of
  hardcoded Dutch keyword constants. Patterns may be literal substrings or a
  `regex:` body, and the bundled set covers tax / social-security / broadcast-fee
  agencies across all 27 EU member states plus the UK, US, Canada, and Ukraine
  (e.g. Finanzamt, DGFiP/URSSAF, HMRC, IRS, Canada Revenue Agency, the German
  Rundfunkbeitrag). Patterns are collision-safe: risky short acronyms use
  `regex:\b…\b` word boundaries or the agency's full name, and agencies whose
  statement descriptors carry only a payment reference (not the agency name)
  are deliberately omitted to avoid false positives.

- The internal bank-statement parsers are no longer branded around a single
  bank: the generic CAMT.053 and MT940 adapters, their helpers, and the shared
  amount parser moved from `Internal/Adapters/Asn` to a neutral
  `Internal/Adapters/Banking` namespace (`Camt053Adapter`, `Mt940Adapter`,
  `BankAmountParser`, …), and the matching Import-module payment-type hinters to
  `Internal/Parsers/Banking`. ASN's own proprietary CSV adapter keeps its name
  (it is one specific bank's format, like the new N26/Revolut/ING presets). No
  behaviour change; format keys are unaffected.
- The bundled merchant corpus is reorganised into per-country files
  (`resources/corpus/merchants/<country>.yaml`, region inferred from filename)
  and expanded to ~600 merchants across all 27 EU member states plus the UK,
  US, Canada, and Ukraine — supermarkets, fuel/energy, telecom, streaming,
  transport, retail, insurance, and food delivery, with pan-European
  subscriptions and payment-facilitator prefixes (`PAYPAL *`, `GOOGLE *`,
  `AMZN MKTP`) in `merchants/eu.yaml`. Merchant patterns support the same
  `regex:` prefix as the rest of the corpus, used to give collision-prone short
  brand tokens (DIA, ICA, NOS, TIM, …) word boundaries so they no longer match
  inside ordinary words.

### Changed

- User-facing copy and the README now describe beatrax as a generic European
  personal-finance tool (any bank that exports CAMT.053, MT940, or CSV, plus
  cards and PayPal) rather than an ASN-specific one. Format-specific names and
  the genuine ASN-format parsers are unchanged.
- Updated dependencies to clear outstanding Dependabot updates: guzzlehttp/guzzle,
  google/apiclient-services, nativephp/desktop, larastan, and Pest on the PHP
  side; fuse.js, vite, and concurrently on the front-end side.
- Renamed the generic statement-format identifiers `asn-camt053` → `camt053`
  and `asn-mt940` → `mt940`, since CAMT.053 (ISO 20022) and MT940 (SWIFT) are
  pan-European standards rather than ASN-specific formats. Existing imports are
  migrated automatically; the ASN-specific CSV layout (`asn-csv`) keeps its name.
- The personal-transfer counterparty heuristic now recognises **any valid SEPA
  IBAN**, not just Dutch ones, using proper mod-97 + country-length validation
  (via `jschaedl/iban-validation`). A German, French, or Belgian personal
  transfer is now classified as a person rather than falling through to
  "unknown". All test/demo fixtures use real, checksum-valid IBANs.

### Fixed

- Resolve seven static-analysis findings surfaced by the larastan/phpstan
  upgrade (the OAuth secrets store's per-inbox map is now typed with the
  array-key it actually uses), and cap phpstan's worker count so the quality
  gate runs deterministically in the Docker toolchain.
- The Docker dev toolchain now points the Pest/PHPUnit suite at the isolated
  `sqlite_testing` connection instead of the WAL-configured `sqlite`
  connection, so the full test suite runs cleanly in the container instead of
  failing thousands of tests on a `RefreshDatabase` isolation clash.

## [1.1.1] - 2026-06-06

### Fixed

- Long Windows installation time and a loopback `403` request flood on first
  launch; the local toolchain now standardises on Docker. (#16)

## [1.1.0] - 2026-05-31

### Changed

- Require PHP 8.5 as the project runtime floor.
- Run the CI quality gates on PHP 8.5 only; the PHP 8.4 leg is dropped.

### Added

- A PHP 8.5 Docker toolchain for running the quality gates (Pint, Larastan,
  Pest) locally without a host PHP install.

### Fixed

- The desktop queue worker now survives past PHP's 120-second wall-clock limit
  on Windows.
- The demo seeder produces a deterministic transaction count.

### Performance

- Large imports are persisted in bounded chunks instead of a single
  long-running transaction.

## [1.0.3-beta] - 2026-05-29

### Fixed

- Pre-warm the Blade view cache at NativePHP boot to defuse a Windows rename
  race during first launch.

## [1.0.2-beta] - 2026-05-29

### Fixed

- Build Vite assets on every platform build leg and raise the bundled PHP
  execution-time limit so packaged installers ship working front-end assets.

## [1.0.1-beta] - 2026-05-28

First public preview release.

### Added

- Native installers for macOS (Apple Silicon), Windows, and Linux, built and
  released from tagged commits.
- Signed auto-update manifests (Ed25519) verified by installed bundles before
  any update is downloaded.
- A `workflow_dispatch` escape hatch to re-build a release for an existing tag.
- The core "this month at a glance" dashboard: ingestion of ASN (CSV, MT940,
  CAMT.053), ICS card statements, and PayPal exports; the ledger; counterparty
  resolution; categorization and triage; recurring detection; drift alerts; and
  forecasting charts.

[Unreleased]: https://github.com/nightworksio/beatrax/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/nightworksio/beatrax/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nightworksio/beatrax/compare/v1.0.3-beta...v1.1.0
[1.0.3-beta]: https://github.com/nightworksio/beatrax/compare/v1.0.2-beta...v1.0.3-beta
[1.0.2-beta]: https://github.com/nightworksio/beatrax/compare/v1.0.1-beta...v1.0.2-beta
[1.0.1-beta]: https://github.com/nightworksio/beatrax/releases/tag/v1.0.1-beta
