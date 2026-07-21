# Features

Per-module deep dives following a shared `_template/` shape. Each module
that earns its own write-up gets four files describing what it does,
the public contract it exposes, the events it raises or listens to, and
the operational notes a reader needs to extend or debug it safely.

## Index

| Module | What it does | Deep dives |
|---|---|---|
| [`auth`](auth/architecture.md) | Per-user authentication, recovery codes, owner-resets-partner, the no-SMTP password-reset posture | [architecture](auth/architecture.md) · [code](auth/code.md) · [specs](auth/specs.md) · [tests](auth/how-to-test.md) |
| [`budgets`](budgets/architecture.md) | Zero-based envelope budgeting — genesis-to-target carryover fold, move-money between envelopes, per-envelope over-budget nudges | [architecture](budgets/architecture.md) |
| [`categorization`](categorization/architecture.md) | Rule-based classifier + per-user merchant memory, the seeded default-rule corpus, the receipt-vs-statement conflict resolver | [architecture](categorization/architecture.md) · [code](categorization/code.md) · [specs](categorization/specs.md) · [tests](categorization/how-to-test.md) |
| [`chains`](chains/architecture.md) | Cross-source funding chains — PayPal funding (deterministic + ASN-direct + fuzzy) + ICS bulk-iDEAL settlement, the chain-links ledger and review queue | [architecture](chains/architecture.md) · [code](chains/code.md) · [specs](chains/specs.md) · [tests](chains/how-to-test.md) |
| [`community`](community/architecture.md) | Bundled YAML corpus + crowd-sourced merchant identification, the suggest-mapping GitHub Compare flow, the github.com-only OpenExternalUrlAction | [architecture](community/architecture.md) · [code](community/code.md) · [specs](community/specs.md) · [tests](community/how-to-test.md) |
| [`core`](core/architecture.md) | The dependency-graph floor — User, BelongsToUser, Clock, CurrentUser, UserDataPathService, the health endpoint, the auto-update channel, the system-alerts banner | [architecture](core/architecture.md) · [code](core/code.md) · [specs](core/specs.md) · [tests](core/how-to-test.md) |
| [`counterparties`](counterparties/architecture.md) | 5-type counterparty taxonomy + 7-step resolution chain, the `/counterparties` index + profile + triage surfaces, the garbage-collector job | [architecture](counterparties/architecture.md) · [code](counterparties/code.md) · [specs](counterparties/specs.md) · [tests](counterparties/how-to-test.md) |
| [`desktop`](desktop/architecture.md) | NativePHP quarantine — first-launch bootstrap, OS notifications, file-open intake, window-close behaviour, OS-theme probe | [architecture](desktop/architecture.md) · [code](desktop/code.md) · [specs](desktop/specs.md) · [tests](desktop/how-to-test.md) |
| [`dev-mode`](dev-mode/architecture.md) | In-app `/dev/*` console — whitelisted artisan runner, log tailer, queue inspector, redacted dev-mode audit log, ⌘K command palette | [architecture](dev-mode/architecture.md) · [code](dev-mode/code.md) · [specs](dev-mode/specs.md) · [tests](dev-mode/how-to-test.md) |
| [`drift-alerts`](drift-alerts/architecture.md) | Subscription-drift watch — per-series threshold ladder, deterministic evaluator, queued detection, snooze + revive lifecycle | [architecture](drift-alerts/architecture.md) · [code](drift-alerts/code.md) · [specs](drift-alerts/specs.md) · [tests](drift-alerts/how-to-test.md) |
| [`email-scan`](email-scan/architecture.md) | Gmail + Microsoft Graph (never IMAP) — OAuth handshake, encrypted oauth_secrets, per-inbox scan state machine, chmod-0600 .eml blob store | [architecture](email-scan/architecture.md) · [code](email-scan/code.md) · [specs](email-scan/specs.md) · [tests](email-scan/how-to-test.md) |
| [`forecasting`](forecasting/architecture.md) | 30/60/90-day cash-flow projection with P10/P50/P90 percentile bands, scenarios + shortfall windows, the chain-aware router | [architecture](forecasting/architecture.md) · [code](forecasting/code.md) · [specs](forecasting/specs.md) · [tests](forecasting/how-to-test.md) |
| [`import`](import/architecture.md) | ImportPipeline orchestrator — preview/confirm wizard, per-source PaymentTypeHinter + StartingBalanceDetector registries, the merchant-alias surface | [architecture](import/architecture.md) · [code](import/code.md) · [specs](import/specs.md) · [tests](import/how-to-test.md) |
| [`ingestion`](ingestion/architecture.md) | Source-format adapters — ASN CAMT.053 / MT940 / CSV, ICS PDF, PayPal CSV; HeaderSniffer pre-parse validation; user-declared format only | [architecture](ingestion/architecture.md) · [code](ingestion/code.md) · [specs](ingestion/specs.md) · [tests](ingestion/how-to-test.md) |
| [`ledger`](ledger/architecture.md) | The canonical store — transactions, accounts, categories, currencies, the v3 fingerprint dedup, the "this period at a glance" dashboard read | [architecture](ledger/architecture.md) · [code](ledger/code.md) · [specs](ledger/specs.md) · [tests](ledger/how-to-test.md) |
| [`onboarding`](onboarding/architecture.md) | `/setup-wizard` six-step first-run flow + per-account starting-balance confirm + delegate-to-owning-module connector steps | [architecture](onboarding/architecture.md) · [code](onboarding/code.md) · [specs](onboarding/specs.md) · [tests](onboarding/how-to-test.md) |
| [`receipts`](receipts/architecture.md) | Per-sender receipt matchers (PayPal / ICS / Google Play) — `.eml` + `.mbox` consumption, enrichment + statement-summary writes, chain-hint dispatch | [architecture](receipts/architecture.md) · [code](receipts/code.md) · [specs](receipts/specs.md) · [tests](receipts/how-to-test.md) |
| [`recurring`](recurring/architecture.md) | Recurring-series detection (expense + income), always-suggest-never-auto-apply state machine, per-series cadence + drift thresholds | [architecture](recurring/architecture.md) · [code](recurring/code.md) · [specs](recurring/specs.md) · [tests](recurring/how-to-test.md) |
| [`sync`](sync/architecture.md) | CRDT op-log multi-device sync — Noise-protocol LAN transport + opt-in zero-knowledge relay, per-user GDK at-rest encryption, QR/word-code pairing with safety-number trust | [architecture](sync/architecture.md) |
| [`transfers`](transfers/architecture.md) | Self-transfer pair detection — deterministic matcher + per-row listener + bulk orphan-sweep in the chain-resolution job | [architecture](transfers/architecture.md) · [code](transfers/code.md) · [specs](transfers/specs.md) · [tests](transfers/how-to-test.md) |

## Template

The canonical 4-file shape every module's deep dive follows lives at
[`_template/`](_template/). New modules add a sibling directory that
mirrors the template's four files (architecture / code / specs /
how-to-test).
