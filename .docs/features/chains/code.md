# `Chains` — code

The file-level map for the module.

## Directory layout

```
Modules/Chains/
├── Public/
│   ├── Contracts/
│   │   ├── DispatchesChainResolution.php
│   │   └── UpsertsCardStatements.php
│   ├── Actions/
│   │   ├── ConfirmChainLink.php
│   │   ├── RejectChainLink.php
│   │   └── DismissChainLinkHint.php
│   ├── Dto/
│   │   ├── ChainLinkRow.php
│   │   ├── ChainLinkHintRow.php
│   │   ├── ChainTree.php
│   │   ├── ChainTreeNode.php
│   │   ├── CardStatementForecastTile.php
│   │   ├── StatementSettlement.php
│   │   ├── NextSettlementDto.php
│   │   └── SeriesFunderLink.php
│   ├── Enums/
│   │   ├── CardStatementCreditReason.php
│   │   ├── CardStatementState.php
│   │   ├── ChainLinkKind.php
│   │   ├── ChainLinkState.php
│   │   └── ConfidenceTier.php
│   ├── Support/
│   │   └── SettlementTolerance.php
│   ├── Http/Livewire/
│   │   └── ChainDrawer.php
│   └── Services/
│       ├── ChainLinkQuery.php
│       └── CardStatementQuery.php
├── Internal/
│   ├── AutoPromotion.php
│   ├── CardStatementStateMachine.php
│   ├── ChainLinkInsertHelper.php
│   ├── ChainTreeWalker.php
│   ├── ConfidenceScale.php
│   ├── PaypalFundingSignatureKey.php
│   ├── Enums/
│   │   ├── ChainLinkResolver.php
│   │   └── SettlementToleranceUsed.php
│   ├── Exceptions/
│   │   ├── CardStatementNotFoundException.php
│   │   ├── ChainLinkNotDismissableException.php
│   │   ├── ChainLinkRequiresConcretePartnerException.php
│   │   └── EvidenceEncodingFailedException.php
│   ├── Presentation/
│   │   ├── ChainLinkRowFactory.php
│   │   ├── CounterpartyDisplay.php
│   │   ├── HintEvidenceSummary.php
│   │   ├── SettlementGroup.php
│   │   └── SettlementLeg.php
│   ├── Resolvers/
│   │   ├── IcsSettlementResolver.php
│   │   ├── PaypalFundingResolver.php
│   │   └── RetypeByAliasResolver.php
│   ├── Jobs/
│   │   └── ResolveChainLinksJob.php
│   ├── Listeners/
│   │   └── CreateChainLinkFromHint.php
│   ├── Services/
│   │   ├── BusChainResolutionDispatcher.php
│   │   └── CardStatementUpserter.php
│   └── Http/Livewire/
│       ├── ChainsIndex.php
│       ├── ChainReviewQueue.php
│       └── ChainHintsQueue.php
├── Models/
│   ├── ChainLink.php
│   ├── CardStatement.php
│   ├── CardStatementCredit.php
│   └── ChainResolutionRun.php
├── Database/
│   ├── Migrations/
│   └── Seeders/
│       └── Demo/DemoChainsSeeder.php
├── Routes/
│   └── web.php
├── Resources/
│   ├── lang/          (26 locales × index/review/drawer/hints)
│   └── views/livewire/
├── Providers/
│   └── ChainsServiceProvider.php
└── tests/
    ├── Unit/
    ├── Feature/
    └── Contracts/
        ├── ChainResolutionIdempotencyTest.php
        └── PaypalFundingCounterLegParityTest.php
```

## Public API

- **Contracts/**
  - `DispatchesChainResolution::dispatchForUser(int $userId)` —
    post-commit dispatch surface called from `ConfirmImport`.
  - `UpsertsCardStatements::upsertForImportRun(int $importRunId,
    User $user)` and `upsertForUser(User $user)` — post-commit
    `card_statements` upserts called from `ConfirmImport` BEFORE the
    dispatcher fires; each returns the number of statements touched.
- **Actions/**
  - `ConfirmChainLink($chainLinkId, $user)` — promotes a candidate;
    runs the auto-promotion learning loop. Throws
    `ChainLinkRequiresConcretePartnerException` if the row's
    `to_transaction_id` is still NULL (hint-shaped rows can't be
    promoted without resolution). Cross-user safety: target row not
    matching `(id, user_id)` raises `NotFoundHttpException`.
  - `RejectChainLink($chainLinkId, $user)` — moves to rejected.
  - `DismissChainLinkHint($chainLinkId, $user)` — clears a hint row
    the user does not want to action.
- **DTOs/**
  - `ChainLinkRow` — flattened review-queue row.
  - `ChainLinkHintRow` — the hint variant (NULL `to`).
  - `ChainTree` + `ChainTreeNode` — recursive tree the chain-drawer
    renders.
  - `CardStatementForecastTile`, `NextSettlementDto`,
    `StatementSettlement` — forecast-side reads consumed by
    `Forecasting`.
  - `SeriesFunderLink` — links a recurring-series to its funder
    (consumed by `Recurring`).
- **Exceptions/**
  - `ChainLinkRequiresConcretePartnerException` — typed exception so
    Livewire renders a friendly message instead of an SQLSTATE 23000.
- **Services/**
  - `ChainLinkQuery::candidatesForReview($user, $cursorId,
    $cursorConfidence, $limit)` — the keyset-paged review queue,
    `ChainLinkQuery::openCandidateCount($user)` (sidebar badge),
    `ChainLinkQuery::forTransaction($transactionId, $user)` — the
    `ChainTree` walk. Also `allChainsForUser` +
    `settlementTotalsForUser` (the /chains pair — the first picks the
    newest settlements and a prefix of each one's legs, the second counts
    and totals every leg so the card does not state the prefix's figures),
    `hasChainForTransaction`, `confirmedFundersForSeries`
    (what `Forecasting` routes on), `hintsForReview` and `hintCount`.
  - `CardStatementQuery::nextSettlementForUser($user)`,
    `CardStatementQuery::openForAccount($accountId, $user)`. The first
    names the payer by reading a confirmed `ics_bulk_settle` link's
    **`from`** side, which is the settlement — the `to` side is a charge
    on the card. Read the other way round the payer filter matched no
    settlement at all, and matched the refund-after-close links instead,
    where both legs sit on the card. The amount it states is
    `open_balance_minor` less the same-currency credits already pointed at
    the statement, floored at zero: that is the payment the resolver will
    settle it with.

## Internal services

- `Internal/CardStatementStateMachine` — single sanctioned mutator of
  `card_statements.state`. Encodes the allowed transitions: `open →
  partially_settled → settled / overpaid`. Other writes are forbidden
  by `noCardStatementStateWritesOutsideMachine`.
- `Internal/ChainLinkInsertHelper` — the `chain_links` INSERT site.
  Every writer goes through it — both resolvers, the hint listener and
  the demo seeder — so no two callers can drift on evidence whitespace,
  key order, or on the pair-uniqueness guard. The hash that backs the
  auto-promotion learning loop is computed against this canonical JSON.
  `idFor()` folds the same pair-uniqueness tuple into the row's id, so
  two devices resolving one hint write one row; both entry points emit
  the `EntityMutated` create.
- `Internal/AutoPromotion` — the learning loop's confirmation
  threshold, and the countdown the review queue renders from it.
- `Internal/ConfidenceScale` — the DECIMAL(4,3) spelling both resolvers
  write `chain_links.confidence` at.
- `Internal/Enums/ChainLinkResolver` — the `resolver` column's
  vocabulary (`auto`, `rule`, `user`) in an 8-character column.
- `Internal/Presentation/ChainLinkRowFactory` — the rows a screen renders,
  built from the `chain_links` rows a query handed it. Both shapes — the
  reviewable link and the endpoint-only hint — read one set of endpoint
  summaries and share the empty one a transaction the reader does not own
  falls back to.
- `Internal/PaypalFundingSignatureKey` — which account a PayPal funding row
  names, and the key standing for it in `evidence.matched_iban`: the IBAN, or
  the account id where the account has none.
- `Internal/Presentation/CounterpartyDisplay` — the decrypt + slug pair
  every chain read path renders a transactions row with, and the
  `counterparties` select expression they join it in by.
- `Internal/Resolvers/IcsSettlementResolver` — decomposes ASN→ICS
  bulk-iDEAL settlements. Names the card two ways, because a card
  answers to two: the `ResolvesKnownCounterpartyIban` contract from
  `Import` maps the ICS institution's fixed IBAN
  `NL08ABNA0526650664` onto the user's ICS account, and a card
  imported from a PDF instead carries the synthetic `ICS-CARD`
  literal in its own `accounts.iban` column. Alias first, own IBAN
  second — the same two arms `ClassifyTransactionType` and
  `TransferPairer` read. Inserts per-statement `chain_links` rows;
  the state machine drives the attached `card_statements` row
  through its lifecycle.
- `Internal/Resolvers/PaypalFundingResolver` — three arms:
  - Deterministic — inspects the PayPal row's stored raw payload for
    `Bankstorting` / `General Withdrawal` / `Transfer to bank` events
    with an IBAN match. Equal-and-opposite `transfer_in` within
    ±`CounterLegWindow::DEFAULT_DAYS` ⇒ confidence 1.0. The counter-leg query is
    [`Transfers`](../transfers/code.md)'s
    `PairLookup::counterLegOnAccount`, called with this resolver's
    own window, direction and ordering
    (`CounterLegOrder::NearestToCentre`), no currency predicate,
    and no already-paired exclusion — the funding leg this arm
    links is one the transfer matcher never pairs.
  - ASN-direct — handles the empirical case where the PayPal CSV
    ships outgoing merchant payments but NOT the SEPA-pull deposits
    that funded them. Pairs the PayPal `expense` directly against an
    ASN `transfer_out` whose counterparty IBAN alias-resolves to one
    of the user's `paypal` accounts.
  - Fuzzy fallback — for rows neither arm decided unambiguously.
- `Internal/Resolvers/RetypeByAliasResolver` — sweeps recently-
  imported rows whose `counterparty_iban` newly resolves through
  `known_counterparty_ibans` and re-types them (e.g.
  `payment_to_merchant` → `transfer_in_from_self`).
- `Internal/Jobs/ResolveChainLinksJob` — `ShouldQueue` +
  `ShouldBeUniqueUntilProcessing` keyed on `userId`. Three tries,
  exponential backoff `[60, 300, 900]`. `uniqueVia()` resolves a
  lock store through `Modules\Core\Public\Support\LockStore::forUniqueJobs()`
  (the single sanctioned `Cache` facade caller; carve-out from the
  DI-only invariant).
- `Internal/Services/BusChainResolutionDispatcher` — the default
  `DispatchesChainResolution` impl. Inserts the audit row, dispatches
  the queued job.
- `Internal/Services/CardStatementUpserter` — the default
  `UpsertsCardStatements` impl. Reads `statement_summaries` written by
  the receipt module and translates them to `card_statements` upserts
  delegated to the state machine.
- `Internal/Listeners/CreateChainLinkFromHint` — listens for
  `Receipts::ChainHintDetected`; inserts a hint-shaped candidate
  `chain_link` (`to_transaction_id = NULL`) per hint.

## Models + migrations

- `Models/ChainLink` — maps to `chain_links`. Uses `BelongsToUser`.
  `evidence` cast as `array`; `confidence` deliberately left without an
  explicit cast (SQLite returns a string; callers cast at the boundary).
  Enum-shaped columns `kind` + `state` enforced by paired BEFORE
  INSERT / BEFORE UPDATE triggers.
- `Models/CardStatement` — maps to `card_statements`. Uses
  `BelongsToUser`. `state` is the lifecycle the state machine
  governs.
- `Models/CardStatementCredit` — maps to `card_statement_credits`. The
  per-credit detail attached to a statement.
- `Models/ChainResolutionRun` — maps to `chain_resolution_runs`. The
  audit row `ConfirmImport` reserves and the job + `JobFailed` listener
  mutate through the `pending → running → complete / failed`
  lifecycle.

Migrations:

- `2026_05_16_010001_create_chain_links_table.php` — initial create
  with the enum triggers (kind, state) and the NULL-endpoint carve-out
  trigger.
- `2026_05_16_010002_create_card_statements_table.php` — statement
  lifecycle ledger with unique `(user_id, account_id, period_start,
  period_end)`.
- `2026_05_16_010003_create_card_statement_credits_table.php` — the
  per-credit detail attached to a statement.
- `2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php`
  — back-populates existing `statement_summaries` rows into the new
  table. Uses `insertOrIgnore` so re-runs are no-ops.
- `2026_05_16_010005_create_chain_resolution_runs_table.php` — the
  audit lifecycle ledger.
- `2026_05_17_010006_extend_chain_links_kind_with_hint_variants.php` —
  extends the `kind` enum with `funded_by_card_hint` and
  `refund_of_hint` for the receipt-driven listener path.

## Provider wiring

`ChainsServiceProvider::register()`:

- Singletons every internal helper (`CardStatementStateMachine`,
  `ChainLinkInsertHelper`, every resolver) and the queued job.
- Binds `DispatchesChainResolution` → `BusChainResolutionDispatcher`.
- Binds `UpsertsCardStatements` → `CardStatementUpserter`.
- Singletons the Public action + query classes and the
  `CreateChainLinkFromHint` listener.

`ChainsServiceProvider::boot()`:

- Loads migrations, web routes, views (each guarded by a file-exists
  check so the module loads cleanly during the partial-build path
  used by some tests).
- Registers four Livewire components under the `chains.*` namespace.
- Subscribes a `JobFailed` listener (private method
  `registerJobFailedListener`) that flips the latest `running` audit
  row to `failed` when `ResolveChainLinksJob` exhausts its retries.
  Uses the injected `Dispatcher` rather than `Queue::failing(...)` so
  the DI-only posture stays satisfied.
- Subscribes `CreateChainLinkFromHint` to
  `Receipts::ChainHintDetected`.
- Registers a view composer for the `shell::livewire.app-sidebar` view
  that merges the open-candidate count into `navCounts` under the
  `chains` key. Resolved through the `ViewFactory` contract — not the
  global `view()` helper — to honour the DI-only invariant, and
  memoised per boot so repeated renders cost one COUNT.
