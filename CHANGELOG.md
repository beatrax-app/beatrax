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
