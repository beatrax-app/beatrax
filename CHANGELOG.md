# Changelog

All notable changes to this project are documented in this file.

This file is the **single source of truth** for release notes. The GitHub Release
body for each tag is generated from the matching section below (see
`scripts/changelog-section.php` and the release workflow) — edit the changelog,
not the release on GitHub.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Breaking Changes

- **Category-linked pots retired** — envelope budgeting (zero-based
  budgeting over your expense categories) replaces category-linked savings
  pots. On upgrade, every category-linked pot is archived and its balance
  released to the account's unallocated pool — re-assign that money into
  the appropriate envelope manually. Goal-linked pots are unaffected and
  keep working exactly as before. Pots can no longer be created or edited
  with a category link; only a goal link (or no link) is available going
  forward.

## [1.3.0] - 2026-06-14

**"Local & in sync"** — the largest release to date. Three tracks shipped:
goals & motivation, take-it-with-you, and insight & records. The fourth track
(local-first end-to-end-encrypted device sync) is in active development for a
follow-up **v1.4** release.

### Added

- **Base-currency FX conversion** — non-EUR account balances now roll into a
  single reporting currency you choose, using a pluggable, offline-capable rate
  source. Closes the gap where non-EUR accounts were silently excluded from the
  net-worth headline.
- **Savings goals** — set a target amount and date, track contributions, and see
  a forecast-driven projected finish date for each goal. The dashboard surfaces
  your nearest-finishing goals at a glance.
- **Savings pots / envelopes** — carve virtual sub-balances ("pots") out of a
  real account and assign money to them without moving it between banks; pots
  reconcile against the real account balance so the numbers always tie out.
- **Responsive, installable PWA** — every screen is now phone-legible, and the
  app installs to your home screen with an offline app-shell. Financial pages
  are never cached offline (privacy by construction); the transactions list
  gets phone infinite-scroll. Charts upgraded (ApexCharts 5).
- **PIN / biometric app-lock** — protect the app with a PIN (Argon2id +
  libsodium key-wrap) and/or device biometrics (WebAuthn / Touch ID), with a
  server-authoritative idle re-lock, a privacy veil, and cross-tab lock sync.
  Also establishes the at-rest key-unlock gate that the upcoming sync feature
  will build on.
- **Bills / cash-flow calendar** — upcoming fixed payments laid out on a
  calendar with a running projected balance, so you can see what's due and what
  you'll have left.
- **Tax-deductible tagging + per-year export** — tag tax-relevant transactions
  (with an optional deduction category and note) from the transactions list,
  detail view, counterparty profile, or cash book; review a whole year on the
  `/tax` cockpit; and export the year's set as CSV or PDF for your records.
  Ships a six-country deduction-category corpus and a setup-wizard step.
- **Full-text search over your entire history** — a fast FTS5 index over
  merchant names, descriptions, and tax notes powers a ⌘K command palette and a
  search-and-filter surface on `/transactions`, with date / account / amount /
  category filters and typed `account:` / `category:` / `amount:` / `after:` /
  `before:` tokens.
- **Unusual-charge / anomaly alerts** — beatrax now proactively flags charges
  that look out of the ordinary: much larger than your typical spend for that
  merchant or category (robust median/MAD statistics), a large charge at a
  brand-new merchant, or an apparent duplicate within a short window (excluding
  known recurring series). Alerts appear through the existing alerts surface via
  a "Subscription drift ↔ Unusual charges" switch, with reason chips, a
  dashboard tile, and a nav badge. Each alert is acknowledge-/snooze-/dismiss-
  able; "mark as expected" creates a narrow, server-computed suppression rule
  you can see and remove in Settings (with one-tap undo). Detection runs on the
  queue (never slowing imports), with a one-time full-history backfill on first
  activation. Tunable sensitivity and a minimum-amount floor in Settings.

### Security

- The anomaly feature shipped with a full STRIDE threat model verified against
  the implementation (22/22 threats closed): every background-job query is
  explicitly user-scoped, suppression bands are computed server-side (never
  client-trusted), alert state changes go through a single audited state
  machine, and cross-user access returns 404.

## [1.2.0] - 2026-06-07

### Added

- `CHANGELOG.md` as the source of truth for release notes, automatically
  published into each GitHub Release body by the release workflow.
- **Cash book** — a new `/cash` page to hand-enter cash and other off-bank
  spending (or income) so it lands in the same ledger as your imports: manual
  entries categorise, recur-detect, and count toward your month exactly like
  imported ones, because they flow through the canonical recording pipeline
  (a synthetic per-user Cash account + a "manual" source). Manual rows are
  user-owned and deletable (unlike immutable imported rows). The amount field
  accepts plain and Dutch-grouped formats; the chosen category is validated as
  your own; and two genuinely-distinct identical same-day entries both record
  (the recorder bumps the booking time on a fingerprint collision rather than
  dropping the second).
- **Net-worth roll-up** — the dashboard leads with one figure across all
  accounts (assets minus liabilities), with an expandable per-account breakdown.
  Each account's balance is the same anchor the forecast uses as "today's
  balance", already sign-correct (a credit card counts as what you owe). Sums
  EUR-denominated accounts into the headline figure and flags when a non-EUR
  account is left out (no balance FX conversion yet); the internal
  `paypal_funding` routing account is excluded. Backed by a new
  `NetWorthQuery` Public service over the previously-internal balance resolver.
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
  Settings → Data & backup can also **restore** from an encrypted backup: upload
  the `.enc`, enter the passphrase, and type the confirmation phrase. The file is
  decrypted and integrity-checked first — a wrong passphrase or corrupt backup
  throws before anything changes — then a pre-restore snapshot of the current
  database is saved and the verified backup is swapped in (reload to see the
  restored data). Available on the SQLite (desktop) build; on a server, use
  `php artisan db:restore` (which gates on maintenance mode) instead.
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

[Unreleased]: https://github.com/nightworksio/beatrax/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/nightworksio/beatrax/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/nightworksio/beatrax/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nightworksio/beatrax/compare/v1.0.3-beta...v1.1.0
[1.0.3-beta]: https://github.com/nightworksio/beatrax/compare/v1.0.2-beta...v1.0.3-beta
[1.0.2-beta]: https://github.com/nightworksio/beatrax/compare/v1.0.1-beta...v1.0.2-beta
[1.0.1-beta]: https://github.com/nightworksio/beatrax/releases/tag/v1.0.1-beta
