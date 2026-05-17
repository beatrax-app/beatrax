---
phase: 07-email-template-matchers-categorization-learning
plan: 03
subsystem: ingestion
tags: [receipts, ics, googleplay, matcher, eml, chain-hint, chain-links, fingerprint-parity, multi-currency, fx]

requires:
  - phase: 07-email-template-matchers-categorization-learning
    plan: 01
    provides: SenderMatcher contract + MatcherRegistry + EmlMimeReader + ChainHintPayload sub-DTOs + ChainHintDetected event scaffold
  - phase: 07-email-template-matchers-categorization-learning
    plan: 02
    provides: PaypalReceiptMatcher precedent + RecordReceipt + ReceiptSourceAdapter + ParseStage eml/mbox arm + FingerprintParityTest paypal arm + SourceRefRanker paypal-receipt rank
  - phase: 05-chain-resolver-cross-source-funding
    provides: chain_links table + ChainLink model + ChainLinkInsertHelper + Chains module review-queue
  - phase: 03-ics-pdf-ingestion-mijn-portal
    provides: IcsPdfAdapter + ics-sample-tiny.pdf fixture (used as the FingerprintParity ICS twin source)
  - phase: 02-asn-statement-coverage-camt-053-mt940
    provides: FingerprintComposer v3 + NormalizeStage + ENRICHED disposition + cross-format dedup

provides:
  - "IcsReceiptMatcher — exact-suffix domain match on @ics.nl|@icscards.nl (T-07-04 defence); HTML-body fallback; FundedByCardPayload chain-hint when card last-four extracted; PDF-attachment receipts surface as skipped('pdf_attachment_v2_only')"
  - "GooglePlayReceiptMatcher — exact-equality sender match on googleplay-noreply@google.com (bare noreply@google.com rejected); strict GPA.NNNN-NNNN-NNNN-NNNNN order-id; multi-currency native USD + settled EUR leg preservation; refund-subject -> skipped('googleplay-refund-v2')"
  - "FingerprintParityTest ICS arm GREEN — receipt-derived row hashes identically to ICS PDF row through NormalizeStage + FingerprintComposer v3; load-bearing cross-format dedup invariant proven for ICS"
  - "SourceRefRanker extensions — ics-receipt > ics-pdf (cross-format ENRICHED dedup preference); google-play-receipt as a standalone rank with no twin"
  - "ChainHintDetected event bridge — DispatchChainHintsFromReceipt (Receipts/Internal/Listeners) subscribes to TransactionImported, re-hydrates chain_hints from raw_payload, re-emits ChainHintDetected with the canonical sourceTransactionId"
  - "CreateChainLinkFromHint (Chains/Internal/Listeners) — consumes ChainHintDetected, INSERTs candidate chain_links row with kind in {funded_by_card_hint, refund_of_hint}, idempotent on (user, from, kind)"
  - "chain_links.kind trigger enum extended via migration 010006 — adds 'funded_by_card_hint' + 'refund_of_hint' to the BEFORE INSERT/UPDATE allow-list; extends the to_transaction_id NULL guard so the two new hint kinds may carry NULL endpoints while in candidate state"
  - "ReceiptSourceAdapter threads ParsedReceiptDto.chainHints[] through SourceTransactionDto.rawPayload['chain_hints'] as an array-of-arrays with hint_type discriminator so the canonical raw_payload column carries the audit trail"
affects: [phase-07-04-categorization-learning, phase-07-05-rules-ui, phase-05-chain-resolver]

tech-stack:
  added: []
  patterns:
    - "Cross-format fingerprint parity twin via the existing tiny PDF fixture for ICS — the consumer portal is PDF-only (project memory: ICS Cards consumer portal is PDF-only), so there is no CSV twin to forge; the IcsReceiptMatcher fixture is deliberately aligned with the SYNTHETIC ICS TINY row the tiny PDF contains so both paths converge bit-for-bit at FingerprintComposer.compose"
    - "Post-persistence event bridge via TransactionImported -> ChainHintDetected — receipts dispatch the structured cross-source clue event AFTER the canonical transactions row is written, because RecordReceipt itself does not write the canonical row (it owns file_imports lifecycle + matcher dispatch); the bridge listener picks the just-inserted transactions.id off the TransactionImported event for the FK-binding sourceTransactionId field"
    - "ParsedReceiptDto.chainHints serialised through rawPayload['chain_hints'] as an array of [hint_type, type-specific-fields, evidence] tuples so the canonical raw_payload column round-trips the typed sub-DTO without an extra side table"
    - "chain_links.kind enum extension pattern — DROP existing trigger pair + recreate with extended allow-list (SQLite cannot ALTER TRIGGER); down() restores the original triggers verbatim"
    - "Listener idempotency via (user, from_transaction_id, kind) existence check before INSERT — a manually-rejected row stays rejected because the listener refuses to write a fresh candidate over it"
    - "Refund handling deferred via skipped reason for v2 — GooglePlayReceiptMatcher matches refund subjects and returns skipped('googleplay-refund-v2') because the matcher cannot resolve the original-order id to a transactions row without DB access; a future Chains resolver picks up the work"

key-files:
  created:
    - "Modules/Receipts/Internal/Matchers/IcsReceiptMatcher.php"
    - "Modules/Receipts/Internal/Matchers/GooglePlayReceiptMatcher.php"
    - "Modules/Receipts/Internal/Listeners/DispatchChainHintsFromReceipt.php"
    - "Modules/Receipts/tests/Unit/Matchers/IcsReceiptMatcherTest.php"
    - "Modules/Receipts/tests/Unit/Matchers/GooglePlayReceiptMatcherTest.php"
    - "Modules/Receipts/tests/Feature/ChainHintFromReceiptTest.php"
    - "Modules/Receipts/tests/fixtures/ics/current-receipt.eml"
    - "Modules/Receipts/tests/fixtures/ics/prior-generation-receipt.eml"
    - "Modules/Receipts/tests/fixtures/ics/pdf-attachment-receipt.eml"
    - "Modules/Receipts/tests/fixtures/ics/spoofed-sender.eml"
    - "Modules/Receipts/tests/fixtures/googleplay/current-receipt.eml"
    - "Modules/Receipts/tests/fixtures/googleplay/foreign-currency-receipt.eml"
    - "Modules/Receipts/tests/fixtures/googleplay/refund-receipt.eml"
    - "Modules/Receipts/tests/fixtures/googleplay/spoofed-sender.eml"
    - "Modules/Chains/Internal/Listeners/CreateChainLinkFromHint.php"
    - "Modules/Chains/Database/Migrations/2026_05_17_010006_extend_chain_links_kind_with_hint_variants.php"
  modified:
    - "Modules/Receipts/Public/Pipeline/ReceiptSourceAdapter.php (threads chain hints through rawPayload['chain_hints'])"
    - "Modules/Receipts/Public/Actions/RecordReceipt.php (docblock updated to explain post-persistence dispatch placement)"
    - "Modules/Receipts/Providers/ReceiptsServiceProvider.php (DispatchChainHintsFromReceipt singleton + TransactionImported listener registration)"
    - "Modules/Receipts/tests/Contracts/FingerprintParityTest.php (ICS arm activated against ics-sample-tiny.pdf as the twin source; google-play arm explicitly skipped with rationale)"
    - "Modules/Chains/Providers/ChainsServiceProvider.php (CreateChainLinkFromHint singleton + ChainHintDetected listener registration)"
    - "Modules/Import/Public/Services/SourceRefRanker.php (ics-receipt + ics-pdf + google-play-receipt ranks)"
    - "Modules/Import/tests/Unit/SourceRefRankerTest.php (3 new tests covering the ics-receipt > ics-pdf ordering + google-play-receipt rank presence)"

key-decisions:
  - "Phase 07 Plan 03: The ChainHintDetected event is NOT dispatched from RecordReceipt (as the plan spec suggested) but from a new Receipts-internal listener DispatchChainHintsFromReceipt that subscribes to TransactionImported. RecordReceipt does not write the transactions row — it owns the file_imports lifecycle + matcher dispatch; the canonical row is persisted downstream by RecordTransactions. Only at TransactionImported time is the just-inserted transactions.id known, which the ChainHintDetected.sourceTransactionId field needs for the chain_links FK constraint to bind. ReceiptSourceAdapter serialises chainHints[] into rawPayload['chain_hints'] so they ride through the canonical pipeline; the bridge listener re-hydrates each entry by hint_type discriminator and re-emits ChainHintDetected with the canonical sourceTransactionId."
  - "Phase 07 Plan 03: chain_links.kind enum trigger needed two new values for the listener's candidate-row INSERT (funded_by_card_hint + refund_of_hint). Migration 010006 DROPs the existing trigger pair and recreates it with the extended allow-list; the to_transaction_id NULL guard is also extended so the two new hint kinds may carry NULL endpoints while in candidate state. The existing exceeded-tolerance ics_bulk_settle carve-out is preserved verbatim. down() restores the original triggers."
  - "Phase 07 Plan 03: ICS fingerprint parity uses the existing tiny ICS PDF fixture (ics-sample-tiny.pdf) as the twin source rather than authoring a synthetic CSV. The Mijn ICS consumer portal is PDF-only per project memory, so no CSV ingestion path exists. The receipt fixture is deliberately aligned with the SYNTHETIC ICS TINY row the tiny PDF contains so both paths converge bit-for-bit at FingerprintComposer.compose. Plan's spec referenced 'Albert Heijn' (a row that lives in the long ics-sample-1.txt fixture, not ics-sample-tiny.pdf), so the alignment was to SYNTHETIC ICS TINY instead."
  - "Phase 07 Plan 03: Google Play fingerprint parity is intentionally SKIPPED with a documented rationale ('no twin ingestion path in v1 — parity covered by GooglePlayReceiptMatcherTest + the shared NormalizeStage exercised by paypal + ics arms'). Google Play has no second ingestion path in v1; a same-row fingerprint parity assertion would have no twin to compare against."
  - "Phase 07 Plan 03: GooglePlayReceiptMatcher.canHandle uses EXACT email-address equality on 'googleplay-noreply@google.com', not domain-suffix match. Bare 'noreply@google.com' is rejected because Google sends many non-receipt notifications from google.com (account security, calendar invites, marketing). Tighter than the PayPal + ICS matchers (which use domain-suffix equality) because the sender-address surface for Google Play is precisely one address."
  - "Phase 07 Plan 03: GooglePlayReceiptMatcher matches refund subjects and returns skipped('googleplay-refund-v2') because the matcher cannot resolve the original-order id to a transactions row without DB access. A future Chains resolver picks up the refund-pairing work via the order-id token populated in transactions.source_ref."
  - "Phase 07 Plan 03: IcsReceiptMatcher.match handles PDF-attachment receipts (monthly-statement attachment shape) by returning skipped('pdf_attachment_v2_only') when an inline-body amount anchor is absent AND a *.pdf attachment is reported by zbateson. Parsing the PDF bytes is a deferred Phase 7 v2 capability."
  - "Phase 07 Plan 03: The Receipts module's existing class_exists() guard pattern in ReceiptsServiceProvider auto-promoted both IcsReceiptMatcher (Task 1) and GooglePlayReceiptMatcher (Task 2) into the receipts.matcher tagged collection the moment their class files landed on disk — no provider edit was needed beyond the Wave 1 / Wave 0 scaffolding. The MatcherRegistry's tagged() lookup picks them up automatically."
  - "Phase 07 Plan 03: ICS receipts populate FundedByCardPayload chain hints when the body carries 'eindigend op 1234' or 'kaart **** 1234'. The chain_link the listener writes is in state='candidate', kind='funded_by_card_hint', to_transaction_id=NULL, resolver='auto', confidence='0.500'. A future Chains-module resolver promotes the row to confirmed once the matching ICS card statement row binds. The resolver is the right home for the cross-source pairing because it needs access to the transactions table to find the funder; the matcher only has the receipt body."

patterns-established:
  - "Post-persistence event bridge for cross-module flows that need the just-inserted row id: a same-module listener on the framework's TransactionImported event re-emits a structured domain event (ChainHintDetected) with the canonical id, which the cross-module listener consumes. Pattern shared by ReceiptsServiceProvider.boot()'s DispatchChainHintsFromReceipt registration and any future post-persistence hook on the ledger."
  - "Schema enum extension migration (SQLite triggers cannot ALTER in place): DROP TRIGGER IF EXISTS + CREATE TRIGGER with the extended allow-list; down() reverses by restoring the original literal allow-list. Pattern shared with Wave 0's matcher_key migration but extended here to multi-trigger pairs (kind + NULL-guard)."
  - "Receipt matcher chain-hint propagation: matchers populate ParsedReceiptDto.chainHints[] when an anchor surfaces; ReceiptSourceAdapter.serializeChainHint flattens each sub-DTO into a `[hint_type, type-specific-fields, evidence]` tuple inside rawPayload['chain_hints']; canonical pipeline persists as JSON; downstream listener re-hydrates by hint_type discriminator. Pattern accommodates additional hint types (refund_of, future split_payment, etc.) without schema churn."
  - "Listener idempotency via pre-INSERT existence check on (user_id, from_transaction_id, kind) — second event for the same triple is a no-op regardless of state; a manually-rejected row stays rejected. Cleaner than relying on a UNIQUE constraint (which would block legitimate (user, from, different-kind) follow-up rows)."
  - "FingerprintParityTest skip pattern with documented rationale: arms that have no twin ingestion path (google-play in v1) markTestSkipped with a one-sentence justification rather than being dropped from the dataset, so a future contributor sees the dataset shape + the reason for the gap."

requirements-completed: [EML-05]

duration: ~150min
completed: 2026-05-17
---

# Phase 7 Plan 03: ICS + Google Play Matchers + Chain Hint Bridge Wave 2 Summary

**End-to-end Wave 2 receipt ingestion: drop an ICS `.eml` via the /imports wizard, the matcher extracts merchant + EUR amount + card last-four + reference; the canonical transactions row lands; the new ChainHintDetected event bridge picks up the chain_hints from raw_payload and triggers a candidate chain_links row (kind=funded_by_card_hint, state=candidate) keyed on the just-inserted transaction id. Google Play `.eml` ingestion ships the same flow without the chain-hint half. FingerprintParityTest ICS arm GREEN against the existing tiny ICS PDF fixture as the twin source — load-bearing cross-format dedup invariant proven for the second receipt family.**

## Performance

- **Duration:** ~150 minutes
- **Started:** 2026-05-17T17:30:00Z (orchestrator hand-off)
- **Completed:** 2026-05-17T20:00:00Z
- **Tasks:** 3
- **Files created:** 16
- **Files modified:** 7
- **Total diff:** 1926 insertions, 41 deletions across 23 files
- **Test count:** 27 new tests landed (11 IcsReceiptMatcher, 12 GooglePlayReceiptMatcher, 4 ChainHintFromReceipt) — all green
- **Full suite:** 1047 passed / 6 skipped / 1 pre-existing failure (TransactionTypeTest, documented as deferred from Wave 0; NOT caused by this plan)

## Accomplishments

- **IcsReceiptMatcher** shipped end-to-end: exact suffix-domain match on @ics.nl + @icscards.nl (T-07-04 spoofing defence — verified by spoofed-sender.eml fixture asserting canHandle false); HTML body fallback via strip_tags + html_entity_decode; current-generation `Verkoper:` + `EUR <amount>` + `eindigend op 1234` + `Referentienummer:` anchors AND prior-generation `Merchant:` + `kaart **** 1234` + `Autorisatiecode:` fallback anchors; PDF-attachment receipts return skipped('pdf_attachment_v2_only') (Phase 7 v2 capability); receipt sign convention NEGATES amounts (EUR 1,00 -> -100) so cross-format fingerprint parity with the ICS PDF row holds; FundedByCardPayload chain hint populated whenever a card last-four extracts.
- **GooglePlayReceiptMatcher** shipped end-to-end: EXACT email-address equality on `googleplay-noreply@google.com` (tighter than the PayPal + ICS matchers — bare `noreply@google.com` is rejected because Google sends many non-receipt notifications from `google.com`); strict GPA.NNNN-NNNN-NNNN-NNNNN order-id format; multi-currency invariant honoured (native `$ 12.99 USD` always emitted as USD pair; parenthesised `(€12,07 EUR)` settled leg surfaces both legs on ParsedReceiptDto so FX information survives through NormalizeStage); refund-subject -> skipped('googleplay-refund-v2') per RESEARCH deferral; merchant extracted from Item: line or falls back to 'Google Play' literal so NormalizeStage never substitutes _no_counterparty.
- **FingerprintParityTest ICS arm GREEN** — receipt-derived canonical row hashes identically to the ICS PDF row through NormalizeStage + FingerprintComposer v3. Uses the existing `ics-sample-tiny.pdf` fixture as the twin source (the Mijn ICS consumer portal is PDF-only per project memory; no CSV ingestion path exists). The receipt fixture is deliberately aligned with the SYNTHETIC ICS TINY row the tiny PDF contains so both paths converge bit-for-bit at FingerprintComposer.compose. The cross-format dedup invariant inherited from Phase 2 is now proven for both receipt families that have a twin ingestion path (paypal + ics).
- **ChainHintDetected event bridge** — new Receipts-internal listener `DispatchChainHintsFromReceipt` subscribes to `TransactionImported`, inspects `transaction.raw_payload['chain_hints']` for chain-hint entries, re-hydrates each by `hint_type` discriminator (`funded_by_card` -> FundedByCardPayload; `refund_of` -> RefundOfPayload), and re-emits `ChainHintDetected` with the just-inserted transactions.id as sourceTransactionId + the importing user.id as userId. Architectural deviation from the plan's "dispatch from RecordReceipt" spec: RecordReceipt does not write the transactions row (it owns the file_imports lifecycle + matcher dispatch); only TransactionImported carries the just-inserted id that the chain_links FK constraint needs.
- **CreateChainLinkFromHint** Chains-module listener — consumes `ChainHintDetected`, INSERTs a candidate chain_links row with kind='funded_by_card_hint' (or 'refund_of_hint'), state='candidate', to_transaction_id=NULL, resolver='auto', confidence='0.500'. Idempotent on (user, from_transaction_id, kind) so re-dispatching the same event is a no-op; a manually-rejected row stays rejected. Cross-user safety (T-07-09): the listener trusts the event's authoritative userId field; the chain_links row's user_id is sourced ONLY from event payload, never inferred from the current HTTP session.
- **chain_links.kind enum trigger extended** via migration `010006_extend_chain_links_kind_with_hint_variants` — appends `funded_by_card_hint` + `refund_of_hint` to the BEFORE INSERT/UPDATE allow-list; extends the to_transaction_id NULL guard so the two new hint kinds may carry NULL endpoints while in candidate state. The existing exceeded-tolerance ics_bulk_settle carve-out is preserved verbatim. down() reverses by restoring the original triggers.
- **ReceiptSourceAdapter** extended to thread chain hints through rawPayload — `serializeChainHint` flattens each sub-DTO into a `[hint_type, type-specific-fields, evidence]` tuple; the canonical pipeline persists as JSON via the existing `transactions.raw_payload` column; the downstream bridge listener re-hydrates by `hint_type`. Pattern accommodates additional hint types without schema churn.
- **SourceRefRanker extended** with three new entries (ics-receipt rank 2 above ics-pdf rank 1; google-play-receipt rank 1 as standalone). Three new ranker tests assert the ordering and the empty-reference zero case.
- **End-to-end feature test** `ChainHintFromReceiptTest` covers four scenarios: full wizard drop -> transaction + chain_link land; ChainHintDetected event payload shape (sourceTransactionId == transaction.id; userId == importing user; hintPayload instanceof FundedByCardPayload with card_last4='1234'); ParseStage NEVER dispatches ChainHintDetected directly (spy assertion); re-drop is a no-op (file_imports UNIQUE + listener idempotency together).

## Task Commits

1. **Task 1: IcsReceiptMatcher + FingerprintParity ICS arm + SourceRefRanker** — `aa09390` (feat)
   - IcsReceiptMatcher with HTML-body fallback + FundedByCardPayload emission + PDF-attachment v2 escape
   - 4 ICS .eml fixtures (current, prior-generation, pdf-attachment, spoofed-sender)
   - FingerprintParityTest ICS arm activated using ics-sample-tiny.pdf as the twin source; google-play arm explicitly skipped with rationale
   - SourceRefRanker adds ics-receipt + ics-pdf + google-play-receipt ranks; 3 new ranker tests
   - 11 IcsReceiptMatcher unit tests
2. **Task 2: GooglePlayReceiptMatcher with FX-aware extraction** — `e63a778` (feat)
   - GooglePlayReceiptMatcher with EXACT email-equality match + strict GPA order-id + native USD / settled EUR multi-currency preservation + refund-subject skip
   - 4 Google Play .eml fixtures (current, foreign-currency, refund, spoofed-sender)
   - 12 GooglePlayReceiptMatcher unit tests
3. **Task 3: ChainHintDetected event bridge + chain_links candidate writes** — `3f5bd47` (feat)
   - DispatchChainHintsFromReceipt (Receipts/Internal/Listeners) subscribes to TransactionImported and re-emits ChainHintDetected with canonical sourceTransactionId
   - CreateChainLinkFromHint (Chains/Internal/Listeners) consumes ChainHintDetected and INSERTs candidate chain_links rows
   - Migration 010006 extends chain_links.kind trigger + to_transaction_id NULL guard for the two new hint kinds
   - ReceiptSourceAdapter threads chainHints[] through rawPayload['chain_hints']
   - ReceiptsServiceProvider + ChainsServiceProvider listener registrations
   - RecordReceipt docblock updated to explain the architectural placement honestly
   - 4 end-to-end ChainHintFromReceiptTest feature tests

## Files Created/Modified

See `key-files` frontmatter above.

## Decisions Made

See `key-decisions` frontmatter above. 9 architectural / implementation decisions surfaced during execution.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 4 - Architectural] ChainHintDetected event dispatch site changed from RecordReceipt to a TransactionImported listener**

- **Found during:** Task 3 design + implementation
- **Issue:** The plan spec instructed "RecordReceipt dispatches the event AFTER it persists the canonical row so sourceTransactionId is the just-inserted row id" — but RecordReceipt does NOT actually write the transactions row. It owns the `file_imports` row lifecycle + matcher dispatch, then yields a MatchOutcomeDto back to the caller (ParseStage). The canonical row is persisted downstream by `RecordTransactions` after NormalizeStage + FingerprintStage finalise the canonical shape. Only at the moment `TransactionImported` fires is the just-inserted `transactions.id` known — which is the value `ChainHintDetected.sourceTransactionId` must carry so the Chains module's INSERT into `chain_links` satisfies the FK constraint on `from_transaction_id`.
- **Fix:** Built a new Receipts-internal listener `DispatchChainHintsFromReceipt` that subscribes to `TransactionImported`, inspects `transaction.raw_payload['chain_hints']` for chain-hint entries, re-hydrates each by `hint_type` discriminator, and re-emits `ChainHintDetected` with the canonical sourceTransactionId. The chainHints[] themselves are serialised by `ReceiptSourceAdapter::toSourceDto()` into `rawPayload['chain_hints']` so they ride through the canonical pipeline. The end-result satisfies the plan's invariant: `ChainHintDetected.sourceTransactionId` is the just-inserted transactions.id; ParseStage never dispatches the event directly (spy test asserts this). RecordReceipt's docblock is updated to honestly describe the placement.
- **Files modified:** Modules/Receipts/Internal/Listeners/DispatchChainHintsFromReceipt.php (new), Modules/Receipts/Public/Pipeline/ReceiptSourceAdapter.php (chain-hint serialisation), Modules/Receipts/Public/Actions/RecordReceipt.php (docblock), Modules/Receipts/Providers/ReceiptsServiceProvider.php (listener registration)
- **Verification:** 4 ChainHintFromReceiptTest feature tests cover end-to-end behaviour incl. the ParseStage non-dispatch spy assertion; BoundaryArchTest green (no module boundary violations); full suite 1047 passed.
- **Committed in:** `3f5bd47` (Task 3 commit)

**2. [Rule 2 - Missing Critical] chain_links.kind enum trigger had to be extended via a new migration**

- **Found during:** Task 3 (design — the existing trigger pair only allows kind IN ('paypal_funding','ics_bulk_settle'); the plan-specified listener writes kind='funded_by_card_hint' which the trigger would have aborted at INSERT time)
- **Issue:** The plan said "INSERT chain_links row with state='candidate', link_type='funded_by_card_hint'" — but the existing chain_links schema column is named `kind` (not link_type) and the trigger pair restricts kind to a closed set. Without extending the trigger pair, the listener's INSERT would fail with a SQLite trigger abort regardless of how clean the listener code was.
- **Fix:** Created migration `010006_extend_chain_links_kind_with_hint_variants` that DROPs the existing kind trigger pair and recreates with the extended allow-list ('paypal_funding','ics_bulk_settle','funded_by_card_hint','refund_of_hint'). Also extends the `to_transaction_id` NULL guard so the two new hint kinds may carry NULL endpoints while in candidate state (the whole point of a candidate hint row is that the matching downstream transaction does not yet exist). down() restores the original triggers verbatim.
- **Files modified:** Modules/Chains/Database/Migrations/2026_05_17_010006_extend_chain_links_kind_with_hint_variants.php (new)
- **Verification:** Full ChainLinksSchemaTest suite (13 tests) green incl. the existing exceeded-tolerance ics_bulk_settle NULL-endpoint carve-out and the new funded_by_card_hint NULL-endpoint carve-out exercised via the ChainHintFromReceiptTest feature tests.
- **Committed in:** `3f5bd47` (Task 3 commit)

**3. [Rule 3 - Blocking] FingerprintParityTest ICS fixture alignment — plan said "Albert Heijn" but the tiny PDF contains "SYNTHETIC ICS TINY"**

- **Found during:** Task 1 (writing the FingerprintParityTest ICS arm)
- **Issue:** The plan said "take the SourceTransactionDto whose merchant + amount + bookedAt match current-receipt.eml's Albert Heijn line" — but Albert Heijn is NOT in `ics-sample-tiny.pdf`. It's a row from the long-form `ics-sample-1.txt` fixture, which is used elsewhere via a test-double extractor. The actual content of `ics-sample-tiny.pdf` (verified via `pdftotext`) is one row: `12-04-2026 SYNTHETIC ICS TINY EUR 1,00`.
- **Fix:** Aligned the ICS receipt fixture (`current-receipt.eml`) with the SYNTHETIC ICS TINY row — merchant 'SYNTHETIC ICS TINY', amount EUR 1,00, bookedAt 12 apr 2026. The receipt + PDF row now converge bit-for-bit at FingerprintComposer.compose. Documented the choice in the FingerprintParityTest dataset comment.
- **Files modified:** Modules/Receipts/tests/fixtures/ics/current-receipt.eml, Modules/Receipts/tests/Contracts/FingerprintParityTest.php
- **Verification:** FingerprintParityTest ICS arm passes (the test explicitly asserts identical FingerprintComposer.compose hashes).
- **Committed in:** `aa09390` (Task 1 commit)

**4. [Rule 3 - Blocking] FingerprintParityTest google-play arm has no twin ingestion path — explicit skip with rationale**

- **Found during:** Task 1 (writing the FingerprintParityTest)
- **Issue:** The plan acceptance criteria said "FingerprintParityTest google-play row optionally skips if no twin chosen" — Google Play has no CSV/PDF twin in v1 because Google Play has no second ingestion path. A same-row fingerprint parity assertion would have no comparator to align against.
- **Fix:** The 'google-play' dataset row markTestSkipped with the rationale: "Matcher 'google-play-receipt' has no twin ingestion path in v1 — parity covered by GooglePlayReceiptMatcherTest + the shared NormalizeStage exercised by paypal + ics arms." The matcher's output flows through the same NormalizeStage as every other receipt arm so behaviour is exercised via the matcher unit tests instead.
- **Files modified:** Modules/Receipts/tests/Contracts/FingerprintParityTest.php
- **Verification:** Test skips loudly with a one-sentence rationale; FingerprintParityTest passes overall (1 paypal + 1 ics active; google-play documented skip).
- **Committed in:** `aa09390` (Task 1 commit)

**5. [Rule 1 - Bug] base_path() called at file-scope in FingerprintParityTest dataset — app not booted yet**

- **Found during:** Task 1 (first test run)
- **Issue:** First attempt at the FingerprintParityTest dataset used `base_path('Modules/Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf')` — but datasets are constructed at file-scope before the Laravel app boots, so the `app()` helper inside `base_path()` failed with "Call to undefined method Illuminate\\Container\\Container::basePath()".
- **Fix:** Replaced with a lexical path computed from `__DIR__.'/../../../Ingestion/tests/fixtures/ics/ics-sample-tiny.pdf'` — relative to the test file location, no app boot required.
- **Files modified:** Modules/Receipts/tests/Contracts/FingerprintParityTest.php
- **Verification:** Test runs cleanly; ICS arm green.
- **Committed in:** `aa09390` (Task 1 commit)

**6. [Rule 1 - Bug] AccountResolution::known() takes int not KnownAccount instance**

- **Found during:** Task 1 (first test run)
- **Issue:** First attempt at FixedIcsAccountResolver returned `AccountResolution::known(new KnownAccount($this->accountId))` — but the static helper takes an `int` accountId directly and constructs the KnownAccount internally. PHP TypeError.
- **Fix:** Changed to `AccountResolution::known($this->accountId)`.
- **Files modified:** Modules/Receipts/tests/Contracts/FingerprintParityTest.php
- **Verification:** Test passes.
- **Committed in:** `aa09390` (Task 1 commit)

---

**Total deviations:** 6 auto-fixed (1 Rule 4 architectural, 1 Rule 2 critical functionality, 2 Rule 3 blocking, 2 Rule 1 bugs). The Rule 4 architectural call (dispatching ChainHintDetected from TransactionImported rather than RecordReceipt) was unavoidable — RecordReceipt does not own the transactions row id that ChainHintDetected.sourceTransactionId requires. The final architecture honours the plan's invariants (event payload carries valid canonical transactions.id; ParseStage never dispatches the event directly) while reflecting the actual write topology.
**Impact on plan:** All auto-fixes were necessary for correctness or for the plan's invariants to hold. No scope creep — the chain_links schema extension was the only addition beyond the plan's file list, and it was forced by the listener spec.

## Issues Encountered

- **Pre-existing TransactionTypeTest failure (out of scope):** `Modules\\Ledger\\tests\\Unit\\TransactionTypeTest::it-rejects-an-invalid-transaction-type` continues to fail in the full suite. Documented as deferred in Wave 0 SUMMARY and re-confirmed in plan 02 SUMMARY. Verified pre-existing: not caused by any plan 03 change.

## Process Deviations

None. All commits made on main branch (no worktree was created by the orchestrator despite the parallel_execution context suggesting one would be); no protected-ref operations; no git stash; no destructive operations. The orchestrator's worktree setup did not run in this session — `.git` is a directory (main repo), not a file (worktree). All commits land directly on main.

## Known Stubs

- **GooglePlayReceiptMatcher refund handling deferred to v2.** `skipped('googleplay-refund-v2')` is the v1 outcome for refund-subject receipts. A future Chains-module resolver should pair the refund leg back to its original purchase via the order-id lookup against `transactions.source_ref`. The matcher cannot do this work without DB access (the matcher receives only the .eml bytes + the matcher's own injected EmlMimeReader).
- **IcsReceiptMatcher PDF-attachment receipts deferred to v2.** `skipped('pdf_attachment_v2_only')` is the v1 outcome when an ICS monthly-statement attachment email has no inline body amount AND carries a `*.pdf` attachment. Parsing the PDF bytes is a deferred capability; the existing IcsPdfAdapter handles consumer-portal PDFs (different shape from email-attached PDFs).
- **CreateChainLinkFromHint refund_of_hint branch is unexercised in v1.** No matcher in plan 03 emits `RefundOfPayload` (the matchers only emit `FundedByCardPayload` for now). The listener branch is in place so the Public event contract is total, but it is not exercised by any test. Future plans that ship the refund matcher will add the test coverage.

## Threat Flags

None. The plan's `<threat_model>` covers:
- T-07-04 (spoofing on matcher canHandle) — both new matchers use exact-equality / exact-suffix domain match defeating look-alike domains; spoofed-sender.eml fixtures + matcher tests assert canHandle returns false.
- T-07-09 (cross-user leak in ChainHintDetected event payload) — both DispatchChainHintsFromReceipt and CreateChainLinkFromHint trust the event's authoritative userId field; the chain_links row's user_id is sourced ONLY from event payload, never inferred from session.
- T-07-10 (HTML body parsing) — accepted (low risk); zbateson normalises to UTF-8; strip_tags + regex are read-only; matchers never render the body anywhere; the Blade renderer in plan 04+ will auto-escape via {{ }}.

No new threat surface introduced.

## Self-Check: PASSED

**Created files (spot check via `test -f`):**
- `Modules/Receipts/Internal/Matchers/IcsReceiptMatcher.php` — FOUND
- `Modules/Receipts/Internal/Matchers/GooglePlayReceiptMatcher.php` — FOUND
- `Modules/Receipts/Internal/Listeners/DispatchChainHintsFromReceipt.php` — FOUND
- `Modules/Receipts/tests/Unit/Matchers/IcsReceiptMatcherTest.php` — FOUND
- `Modules/Receipts/tests/Unit/Matchers/GooglePlayReceiptMatcherTest.php` — FOUND
- `Modules/Receipts/tests/Feature/ChainHintFromReceiptTest.php` — FOUND
- `Modules/Receipts/tests/fixtures/ics/{current-receipt,prior-generation-receipt,pdf-attachment-receipt,spoofed-sender}.eml` — ALL FOUND
- `Modules/Receipts/tests/fixtures/googleplay/{current-receipt,foreign-currency-receipt,refund-receipt,spoofed-sender}.eml` — ALL FOUND
- `Modules/Chains/Internal/Listeners/CreateChainLinkFromHint.php` — FOUND
- `Modules/Chains/Database/Migrations/2026_05_17_010006_extend_chain_links_kind_with_hint_variants.php` — FOUND
- This SUMMARY.md — about to be created/committed

**Commits (verified via `git log --oneline | grep`):**
- `aa09390` (Task 1 — IcsReceiptMatcher + FingerprintParity ICS arm + SourceRefRanker) — FOUND
- `e63a778` (Task 2 — GooglePlayReceiptMatcher) — FOUND
- `3f5bd47` (Task 3 — ChainHintDetected bridge + listener + migration) — FOUND

**Verification:**
- 27 new Wave 2 tests green (11 IcsReceiptMatcher unit, 12 GooglePlayReceiptMatcher unit, 4 ChainHintFromReceipt feature)
- 1047 full-suite tests passing (6 skipped legitimately incl. the google-play fingerprint-parity arm with documented rationale; 1 pre-existing TransactionTypeTest failure carried forward from Wave 0)
- PHPStan max + Pint green on Modules/Receipts + Modules/Chains + Modules/Import (the three modules touched)
- BoundaryArchTest 20/20 green incl. Modules\\Receipts\\Internal containment, Modules\\Chains\\Internal containment, and the noEmailFetchFromReceipts invariant (none of the new matchers import any EmailScan OAuth/client symbol)
- FingerprintParityTest paypal arm + ICS arm both pass — load-bearing cross-format-dedup invariant proven for both receipt families that have a twin ingestion path

## Next Phase Readiness

Wave 2 is complete. Wave 3 (Plan 07-04 — categorization learning) inherits:
- Three Phase 7 v1 matchers live (paypal-receipt, ics-receipt, google-play-receipt) all routed via the same RecordReceipt + ParseStage + MatcherRegistry flow
- chain_links candidate rows landing for ICS-card-funded receipts; future Chains resolver promotes them once funders surface
- ParsedReceiptDto.chainHints[] propagation pattern in place for any future hint type
- FingerprintParityTest scaffolding ready for any future receipt family with a twin ingestion path

Phase 4 deferred-items still pending: `Modules\\Ledger\\tests\\Unit\\TransactionTypeTest::it-rejects-an-invalid-transaction-type` (environment-shaped Pest harness issue carried forward since Wave 0).

---
*Phase: 07-email-template-matchers-categorization-learning*
*Plan: 03*
*Completed: 2026-05-17*
