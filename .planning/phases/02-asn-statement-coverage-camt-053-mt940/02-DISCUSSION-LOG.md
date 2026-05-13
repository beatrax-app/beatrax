# Phase 2: ASN Statement Coverage (CAMT.053 + MT940) - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-13
**Phase:** 02-asn-statement-coverage-camt-053-mt940
**Areas discussed:** Cross-format dedup strategy; SEPA ref precedence; MT940 parser + scope; CSV→CAMT re-import semantics

---

## Cross-format dedup strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Bump v3: drop source_ref from fingerprint | FingerprintComposer v3 tuple drops source_ref. Migration re-derives existing CSV rows. Same row across CSV and CAMT hashes identically. Trade-off: same-day-same-merchant collisions. | ✓ |
| Two-pass lookup-then-fingerprint with 'upgrade' path | Keep v2. On CAMT import, lookup by natural keys WHERE source_ref IS NULL and UPDATE. More code; preserves natural-key uniqueness. | |
| Two fingerprints per row: strict + loose | Add `fingerprint_loose` column without source_ref. Two indexes, two hashes per row. Most invasive. | |

**User's choice:** Bump v3 — drop source_ref.
**Notes:** Recommended option; aligns with the FingerprintComposer doc-comment that already advertises the version-bump path. Collision risk handled by follow-up decision (D-22).

### Follow-up: Collision risk under v3

| Option | Description | Selected |
|--------|-------------|----------|
| Widen tuple with booked_at down to seconds | Include booked_at (or its time-of-day component). CAMT/MT940 carry real times; CSV at 00:00. | ✓ |
| Add sequence-within-day counter | Integer suffix per (account, day, amount, merchant) bucket. Order-dependent edge cases. | |
| Accept collision, surface in preview | No tuple change; manual gate via 'POSSIBLE DUPLICATE — review' wizard state. | |

**User's choice:** Widen tuple with booked_at.
**Notes:** Locked as D-22.

---

## SEPA reference precedence as source_ref

| Option | Description | Selected |
|--------|-------------|----------|
| EndToEndId only; null when absent | Prefer Ntry/NtryDtls/TxDtls/Refs/EndToEndId. No fallback to weaker refs. Clean semantics. | ✓ |
| Fallback chain: EndToEndId → AcctSvcrRef → InstrId → TxId | Stronger coverage; semantically heterogeneous source_ref. | |
| Store all four references; source_ref = EndToEndId | sepa_references JSON column or separate table. Most schema. | |

**User's choice:** EndToEndId only.
**Notes:** Recommended; locks `source_ref` semantics for Phase 5 chain joins.

### Follow-up: Where do AcctSvcrRef / InstrId / TxId live?

| Option | Description | Selected |
|--------|-------------|----------|
| rawPayload only; revisit if Phase 5 needs them | SourceTransactionDto.rawPayload already exists. Zero schema cost now. | ✓ |
| Add structured `sepa_refs` JSON column on transactions now | Indexable via virtual columns later. | |
| Don't preserve them; CSV doesn't either | Re-parse on-disk file if Phase 5 needs them. | |

**User's choice:** rawPayload only.
**Notes:** Locked as D-24.

---

## MT940 parser + scope

| Option | Description | Selected |
|--------|-------------|----------|
| Use kingsquare/php-mt940 | Ships with ASN engine. Stagnant since 2020 but stable, 821K installs. | |
| Hand-roll an MT940 parser in pure PHP | Total control, no dep. ~200 LOC for the tokenizer; ASN-specific :86: decoding is the hard part. | ✓ |

**User's choice:** Hand-roll.
**Notes:** Locked as D-25. Contrast with CAMT decision below (genkgo/camt) — user chose dep only where the spec is large and stable (ISO 20022).

### Follow-up: CAMT.053 parser

| Option | Description | Selected |
|--------|-------------|----------|
| Use genkgo/camt | Mature ISO 20022 parser. Handles 001.02/001.03/001.08. Brings moneyphp/money transitive. | ✓ |
| Hand-roll CAMT.053 via SimpleXML/XMLReader | Hundreds of spec pages with namespace versioning. Cost high, benefit low. | |

**User's choice:** Use genkgo/camt.
**Notes:** Locked as D-26.

### Follow-up: MT940 dedup behaviour

| Option | Description | Selected |
|--------|-------------|----------|
| Best-effort, same-pipeline; flag uncertainty in preview | MT940 normalised same as everything else. Imperfect dedup accepted. | |
| MT940 gets a stricter normalize step | Strip GVC codes, BIC prefixes, /REMI/ markers before shared normaliser. | ✓ |
| MT940 is opt-in / quarantine — lands in 'staged' status | status=staged until user reviews. | |

**User's choice:** Stricter normalize step for MT940.
**Notes:** Locked as D-27. MT940-specific pre-normalisation runs before the shared FingerprintComposer::normalize.

---

## CSV → CAMT re-import semantics

| Option | Description | Selected |
|--------|-------------|----------|
| Enrich on dedup: UPDATE existing row's source_ref | Fingerprint match + stronger ref → update existing row. New 'ENRICHED' preview state. | |
| Skip on dedup: existing CSV row keeps source_ref=NULL | Simpler. CSV ran first, CSV's source_ref wins. Phase 5 re-parses on-disk file if needed. | |
| Enrich + track provenance: add `enriched_from` JSON column | Enrich source_ref AND record full audit trail. Most invasive. | ✓ |

**User's choice:** Enrich + track provenance.
**Notes:** Locked as D-28 / D-28a / D-28b / D-28c. The `enriched_from` column carries an audit array; preview wizard gains an ENRICHED state with diff-style indicator.

---

## Claude's Discretion

- Specific batching / WAL-checkpointing strategy for the v3 fingerprint re-derive migration (D-21a).
- Exact SAX-vs-eager API choice inside genkgo/camt; whether to wrap with a buffering iterator.
- MT940 `:86:` ASN-specific narrative decoding rules — the hand-rolled parser's internal lookup tables.
- HeaderSniffer signature byte counts for CAMT XML namespace match and MT940 leading block detection.
- Wire-up location for `genkgo/camt` adapter classes — match nwidart module conventions.
- Statement-coverage view (D-30) UI placement: separate page vs inline panel under the results summary.
- Larastan / Pint / Pest fixtures for new adapters — extend existing test classes; do not invent new layouts.
- Exact `enriched_from` JSON shape inside the column (planner picks the precise field names; D-28a fixes only the conceptual contents).

## Deferred Ideas

- `AcctSvcrRef` / `InstrId` / `TxId` as indexable columns — Phase 5 if needed.
- PayPal Reporting API path (ING-09) — Phase 4.
- ICS Cards / multi-currency display — Phase 3.
- Statement-coverage page polish (balance reconciliation, gap visualisation) — later phase if needed.
- Auto-detect uploaded file format — rejected project-wide by ING-07.
- `kingsquare/php-mt940` as a fallback engine — explicitly rejected; reconsider only if hand-rolled MT940 coverage proves insufficient.
