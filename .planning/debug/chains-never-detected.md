---
slug: chains-never-detected
status: root_cause_found
trigger: |
  Chain detection produces no results after a full import cycle of PayPal,
  ICS Cards, and ASN Bank. Expected at least two chain types: (1) PayPal
  funding chains where a PayPal charge is later funded from ASN; (2) ICS
  Cards ↔ ASN iDEAL settlement chains where ASN bulk-pays the monthly ICS
  statement. Neither type is visible on `/chains` review page or on the
  dashboard / monthly view. This is the first time the user expected chain
  detection to work — never confirmed working before.
goal: find_and_fix
tdd_mode: false
created: 2026-05-27
updated: 2026-05-27
---

# Debug: chains never detected after full import

## Symptoms

**expected**: After importing PayPal + ICS Cards + ASN Bank for an
overlapping period, the `/chains/review` page (and chain badges on the
dashboard/monthly view) should surface at least two kinds of resolved
chains:
- **PayPal funding**: a PayPal charge whose underlying funding pulls
  from the user's ASN account.
- **ICS iDEAL settlement**: monthly ICS statement total settled by a
  single bulk iDEAL payment from ASN to ICS — the ASN debit and the
  ICS statement should link as the funding leg of every ICS charge in
  that statement period.

**actual**: Both `/chains/review` page and the dashboard/monthly view
show no detected chains for these flows. User has confirmed the
transactions are visible in the ledger — ingestion succeeded.

**error messages**: None reported.

**timeline**: First time the user has expected chain detection to
produce results. There is no "it used to work" baseline; this is a
structural wiring + classification gap that has been latent since
the chain-resolver Phase shipped.

**reproduction**: Run a full import cycle of PayPal + ICS Cards + ASN
Bank with overlapping date ranges that include at least one PayPal
charge funded from ASN and at least one ICS monthly statement bulk-
settled via ASN iDEAL. Open `/chains/review` — empty. Open
dashboard — no chain badges on the relevant transactions.

## Verified context

- Surfaces checked: `/chains/review` review page AND dashboard / monthly view
- Prior baseline: never seen chain detection produce results
- Ingestion: confirmed succeeded — transactions are in the ledger
- Module of interest: `Modules/Chains` (interacts with
  `Modules/Ingestion` / `Modules/Ledger` / `Modules/Transfers` /
  `Modules/Import` after import commit)

## Current Focus

hypothesis: **CONFIRMED** — neither resolver can match anything because
no transactions are ever classified as `transfer_in`/`transfer_out` for
cross-account hops. The classifier's only retyping path
(`Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType`
step 2) requires `counterparty_iban` to literally equal one of the
user's own (synthetic) Account.iban values, but ASN reports real
institution IBANs and the PayPal/ICS adapters never set
counterparty_iban at all. The chain detection job IS dispatched and
runs successfully — it just finds zero candidates.
test: gathered structural + live-database evidence (see below)
expecting: confirmed root cause
next_action: produce a Root Cause Report; defer fix to `/gsd:plan-phase
--gaps` because the proper fix is a non-trivial alias-IBAN /
known-counterparty bridge layer (multi-file, multi-module).

## Evidence

- timestamp: 2026-05-27
  source: live database
    (`/Users/wesselverheij/Development/diederik/database/nativephp.sqlite`)
  observation: |
    Direct sqlite3 inspection of the user's production state shows:
      - transactions: 414 rows total
      - accounts: bank=1 (NL12ASNB8850776713 "ASN bank"), ics_card=1
        (synthetic IBAN 'ICS-CARD'), paypal=1 (synthetic IBAN 'PAYPAL')
      - statement_summaries: bank=1, ics_card=2, paypal=1
        (so 2 ICS PDF statements WERE imported)
      - card_statements: ZERO rows
      - chain_resolution_runs: 1 row, status='complete',
        linked_count=0, completed_at=2026-05-27 17:24:53
        (the job ran successfully; it just found nothing to link)
      - chain_links: ZERO rows of any kind
      - jobs queue: 0
      - failed_jobs: 0
  conclusion: |
    The detector pipeline runs end-to-end with no errors. Hypothesis (A)
    "job never dispatched" is REFUTED. The bug is upstream of the
    resolvers: the data the resolvers expect to query is never produced.

- timestamp: 2026-05-27
  source: live database — typing breakdown
  observation: |
    Distribution of `transactions.type` per account.kind:
      - bank (ASN):     expense=213, income=16, transfer_in=0, transfer_out=0
      - ics_card:       expense=125, transfer_in=0
      - paypal:         expense=50,  transfer_in=0
    NOT A SINGLE ROW IS TYPED transfer_in OR transfer_out anywhere in
    the database. Both `IcsSettlementResolver` and
    `PaypalFundingResolver` filter for `type='transfer_in'` (and the
    PayPal resolver also filters its source set on `IN
    ('expense','transfer_out')` — only expense matches there). Their
    candidate-set queries necessarily return empty.
  conclusion: |
    Hypothesis (B) "resolver runs but no candidates" is the actual
    failure mode — and the algorithm is upstream-correct. The bug
    is that the inputs the algorithm expects (typed transfer legs)
    never get produced.

- timestamp: 2026-05-27
  source: live database — counterparty_iban distribution
  observation: |
    On the ASN account, the rows that ARE the PayPal/ICS funding hops:
      - paypal-named rows: 46 rows, counterparty_iban='LU89751000135104200E'
        (PayPal SARL et Cie SCA Luxembourg's real IBAN)
      - ICS-named rows:    3 rows,  counterparty_iban='NL08ABNA0526650664'
        (International Card Services BV's real ABN AMRO settlement IBAN)
    On the ICS card account: 135 expense rows, counterparty_iban=''
    On the PayPal account:    50 expense rows, counterparty_iban=''
    The user's own (synthetic) account IBANs are 'PAYPAL' and 'ICS-CARD'.
  conclusion: |
    `ClassifyTransactionType::run()` step 2 (the ONLY production
    classifier path that produces transfer_in/out) compares
    `counterpartyIban` against `accounts.iban WHERE user_id=$uid`.
    'LU89751000135104200E' will never equal 'PAYPAL'. 'NL08ABNA0526650664'
    will never equal 'ICS-CARD'. So these rows stay typed `expense`
    and `PairTransferCandidates` never fires (it short-circuits on
    `! in_array($tx->type, ['transfer_out','transfer_in'])`).

- timestamp: 2026-05-27
  source: source-code audit — Modules/Ingestion/Public/Paypal/PaypalCsvEventTypeMap.php
  observation: |
    PaypalCsvEventTypeMap::TRANSACTION_TYPE['nl'] only maps the two
    observed parent event types — 'Vooraf goedgekeurde betaling …'
    and 'Express Checkout-betaling' — both to `expense`. There is
    NO mapping that produces `transfer_in`/`transfer_out` for the
    PayPal-side leg of a funding hop ("Bankstorting naar PP-rekening"
    appears in the child-fee classification, not as a parent type
    that gets a transaction-type assignment). Even a Bankstorting-only
    parent row would have no transfer mapping today.
  conclusion: |
    Even if the deterministic arm of `PaypalFundingResolver` could
    inspect the PayPal-side raw_payload (which it CAN — the
    `extractEvents()` path looks for 'General Withdrawal' /
    'Bankstorting' / 'Transfer to bank' event-type strings in the
    raw payload), the `transactions.type` filter at the top of
    `resolveForUser()` (`whereIn('transactions.type',
    ['expense','transfer_out'])`) is satisfied by expense rows —
    BUT the deterministic arm's `findPartnerOnAccount()` then
    requires `transactions.type='transfer_in'` on the ASN side
    (line 443). That ASN row is currently typed `expense`. So
    the deterministic arm also dead-ends.

- timestamp: 2026-05-27
  source: source-code audit — Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php
  observation: |
    `IcsSettlementResolver::resolveForUser()` line 137-156 queries:
      transactions JOIN accounts WHERE accounts.kind='ics_card'
      AND transactions.type='transfer_in'
    This is structurally impossible to satisfy with the current
    classification + ingestion behaviour:
      - The ASN→ICS iDEAL settlement is a transfer FROM the ASN side
        (debit on ASN). The ICS side of the same money movement is
        represented on the ICS PDF as a STATEMENT-LEVEL closing
        balance offset, NOT as a per-row transaction. ICS PDFs
        produce expense rows and a statement_summary row, never a
        synthetic transfer_in on the ICS account side.
      - Even if it WERE represented as a per-row transaction on the
        ICS account, no production code path creates one with
        type='transfer_in'.
  conclusion: |
    The resolver's data model assumes a per-account transfer_in row
    exists on the ics_card account that the user never imports
    (and the adapter never synthesises). This is an algorithm-input
    contract that the rest of the pipeline never honours.

- timestamp: 2026-05-27
  source: source-code audit — backpopulate migration
    (Modules/Chains/Database/Migrations/2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php)
  observation: |
    Card statements are ONLY ever populated by the one-shot
    backpopulate migration that runs at migrate-up time and copies
    existing statement_summaries → card_statements (INSERT IGNORE).
    No production code path (NOT in any adapter, NOT in
    StatementSummaryWriter, NOT in ConfirmImport, NOT in any
    listener/job) INSERTs into card_statements after that one-shot.
    Grep confirms: zero INSERT/upsert sites outside the migration.
    Even if the ICS resolver were fixed to look at ASN-side
    transfer_outs for the bulk settlement, it ALSO needs a
    matching card_statement row — which a fresh ICS PDF import
    (post-migration) would not create.
  conclusion: |
    Even if the classifier issue were fixed in isolation, the ICS
    resolver would STILL produce no chain_links because the
    statement-summaries → card_statements bridge is a one-shot
    migration, not a per-import action. A new ICS PDF import
    after the backfill migration ran produces a statement_summary
    but no card_statement — and the resolver only joins against
    card_statements.

## Eliminated hypotheses

- **(A) job never dispatched** — REFUTED. `chain_resolution_runs`
  shows one `complete` run with `linked_count=0`. The job is wired,
  dispatched (post-commit in `ConfirmImport`), and finishes cleanly.
- **(C) UI surfacing gap** — REFUTED for this scenario. With ZERO
  chain_links rows the page is correctly empty; nothing to surface.
  (If/when chain_links rows exist, UI scoping should be re-verified
  as a separate concern.)

## Root cause

**Cross-account hops between ASN, PayPal, and ICS are never
classified as transfer_in/transfer_out — and the ICS card statement
roll-forward is missing entirely — so both chain resolvers
unconditionally see zero candidate rows.**

Three concrete structural gaps, all of which must be closed for end-
to-end chain detection to produce a single chain_link:

1. **Real-IBAN ↔ synthetic-IBAN bridge is missing.**
   `ClassifyTransactionType::run()` (the only classifier path that
   produces `transfer_in`/`transfer_out`) requires the row's
   `counterparty_iban` to literally equal an own-account
   `accounts.iban`. The user's PayPal and ICS card accounts use the
   synthetic IBAN literals `'PAYPAL'` and `'ICS-CARD'` (intentional —
   PayPal/ICS PDFs have no IBAN per row to use), but the ASN bank
   side reports the *real* counterparty IBANs
   (`LU89751000135104200E` for PayPal Luxembourg,
   `NL08ABNA0526650664` for ICS's ABN AMRO settlement). There is no
   alias / known-counterparty table that maps real institution IBANs
   to the user's synthetic own-account IBANs, so the equality test
   always fails and the row stays `expense`.

2. **PayPal-side and ICS-side transfer legs are never synthesised.**
   The PayPal CSV adapter rolls each multi-event payment into one
   row typed `expense`; even the inner `Bankstorting naar
   PP-rekening` event (the PayPal-side leg of "user topped up
   PayPal from ASN") is classified as a `child-fee` enrichment, not
   as its own canonical row with type `transfer_in`. The ICS PDF
   adapter never emits a per-row transfer for the bulk-iDEAL
   settlement at all — that settlement only exists on the ASN side.

3. **`card_statements` is a one-shot backfill, not a per-import
   write.** Even if (1) and (2) were resolved, the ICS resolver
   queries `card_statements`, which is only populated by the
   `2026_05_16_010004_backpopulate_card_statements_from_statement_summaries`
   migration. A fresh ICS PDF import after that migration ran
   produces a new `statement_summaries` row but no
   `card_statements` row, leaving the resolver's
   `findCandidateStatement()` lookup with an empty candidate set.

## Specialist hint

`general` — the fix is cross-module Laravel/PHP architecture, not
language- or framework-specific. The required pattern (alias-IBAN
table + classifier consultation + per-import card-statement
upsert + PayPal-event-type mapping for funding legs) is plain
constructor-DI Laravel domain code.

## Recommended next action

Defer the fix to a planned phase. `find_and_fix` is not appropriate
for this root cause because the proper resolution spans at least
four modules (Ingestion / Import / Chains / Ledger) and requires:

 a. A new `known_counterparty_ibans` (or similar) table mapping
    real institution IBANs → synthetic own-account aliases per user,
    seeded with PayPal SARL (LU…E) → 'PAYPAL' and ICS ABN AMRO
    (NL08ABNA…) → 'ICS-CARD'. Consulted in
    `ClassifyTransactionType` step 2 before the literal-equality
    fallback.
 b. A per-import `card_statements` upsert path triggered by the
    same post-import boundary that dispatches the chain job — so a
    new ICS PDF lands both a `statement_summaries` row AND a
    `card_statements` row in `state='open'`.
 c. PayPal `TRANSACTION_TYPE` mappings for the funding-event parent
    types (or a new parent-event-extraction rule that emits a
    synthetic PayPal-side `transfer_in` row when an "Algemene
    opname / General Withdrawal / Bankstorting" sub-event appears
    in the rolled-up event tape — this restores the missing
    PayPal-side leg of the funding chain).
 d. Pest tests that assert end-to-end: a fixture ASN row +
    matching PayPal row + matching ICS PDF produce confirmed
    chain_links of both kinds after `ResolveChainLinksJob` runs.

The proper entry point is `/gsd:plan-phase --gaps` with this
debug file as the source-of-truth evidence trail.
