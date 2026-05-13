# Phase 2: ASN Statement Coverage (CAMT.053 + MT940) - Context

**Gathered:** 2026-05-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 2 broadens ASN coverage from the Phase 1 CSV slice to ASN's richer statement formats — CAMT.053 (XML, ISO 20022) as the primary path and MT940 (legacy SWIFT text) as the fallback for older periods — and resolves the cross-format duplication problem that the Phase 1 fingerprint cannot handle on its own.

The user can: upload an ASN CAMT.053 export and see transactions imported with `EndToEndId` captured as the canonical `source_ref`; upload an ASN MT940 export and have it ingested through the same Parse → Normalize → Fingerprint pipeline; re-import the same period across formats without seeing duplicates; and, when a richer format arrives after a weaker one, see the existing rows enriched with the better identifier (a new "ENRICHED" preview state alongside NEW / DUPLICATE / ERROR).

This phase does NOT add ICS Cards, PayPal, chain resolution, recurring detection, email scanning, or multi-currency UI toggling — those are Phases 3 through 11 per ROADMAP.md. It does NOT change Phase 1's UX shape (wizard, preview, dashboard) — only the source-format dropdown grows and the preview row states extend.

</domain>

<decisions>
## Implementation Decisions

### Fingerprint Algorithm (the critical one)

- **D-21:** Bump `FingerprintComposer::NORMALIZATION_VERSION` from **2 → 3**. The v3 tuple **drops `source_ref`**: `user_id | account_id | posted_at | booked_at | amount_minor | currency | counterparty_normalized`. Rationale: the same real-world transaction lands once via CSV with `source_ref = NULL` and once via CAMT with `source_ref = EndToEndId-XYZ` — including `source_ref` in v2 made the two hash differently, defeating Success Criteria #3. Existing v2 rows must be re-derived under v3 during the migration step.
- **D-22:** Widen the v3 tuple with **`booked_at`** (full `dateTime`, second-resolution) to avoid the same-day-same-merchant-same-amount collision that v3 would otherwise introduce. ASN CSV rows whose adapter only knows a date land at `00:00:00`; CAMT and MT940 carry real booking times. Two genuine €5 coffees to STARBUCKS AMSTERDAM on the same day typically differ in seconds; two formats of the SAME transaction agree on the timestamp (or both fall to midnight). The composite UNIQUE index on `transactions(user_id, fingerprint)` from Phase 1 stays — only the *content* of the fingerprint changes.
- **D-21a (data migration):** A migration walks every existing `transactions` row, re-computes the v3 fingerprint from current column values, writes both `fingerprint` and `normalization_version` in a single `UPDATE`. Pre-checks for collisions before bumping the version stamp — if any same-tuple collision is detected in existing data, the migration aborts with a clear error and leaves v2 intact (manual reconciliation required). Planner picks the exact strategy (batch-update vs row-by-row vs temp-table swap) per SQLite WAL constraints.

### SEPA Reference Handling

- **D-23:** `source_ref` for CAMT.053 rows is **`EndToEndId` only** (`Ntry/NtryDtls/TxDtls/Refs/EndToEndId`). When the entry has no `EndToEndId` (some bulk debits, card-settlement aggregates), `source_ref` is `NULL`. No fallback to weaker bank-side refs (`AcctSvcrRef`, `InstrId`, `TxId`) — substituting them would make `source_ref` semantically heterogeneous and break Phase 5's chain joins.
- **D-24:** Secondary SEPA refs (`AcctSvcrRef`, `InstrId`, `TxId`, `MsgId`, plus the full `Ntry`/`TxDtls` block) are preserved verbatim inside `SourceTransactionDto::$rawPayload` so downstream phases (Phase 5 chain resolution especially) can re-extract them without re-parsing the on-disk file. The CAMT adapter serialises the relevant XML fragments into a structured sub-array under `rawPayload['sepa']`. Schema: no new columns on `transactions`. Phase 5 owns any indexing cost if it needs ref-level joins.
- **D-23a (MT940 source_ref):** MT940 has no `EndToEndId` equivalent. The MT940 adapter sets `source_ref` from `:61:` field's customer reference (the bank reference / NOREF marker) when meaningful, else `NULL`. This is intentionally weak — Phase 5 chain resolution will not lean on MT940 source_refs.

### Cross-Format Re-import Semantics (ENRICHMENT)

- **D-28:** When a fingerprint match is found AND the incoming row carries a **stronger** `source_ref` than the existing row, the Import pipeline **enriches** the existing row rather than skipping it. "Stronger" = non-null > null; within non-null, the canonical order is `EndToEndId > AcctSvcrRef > InstrId > MT940 ref > CSV ref`. The pipeline performs an `UPDATE` writing the new `source_ref` and appending a provenance entry to a new `enriched_from` JSON column.
- **D-28a (`enriched_from` column):** New nullable JSON column on `transactions`: an array of `{format, ran_at, import_run_id, added: ['source_ref']}` records, one per import that contributed information to the row. The initial import that created the row is also recorded as the first entry (so the audit trail is complete, not just enrichment deltas). Schema migration runs in this phase.
- **D-28b (preview states):** The Phase 1 preview wizard renders three states (NEW / DUPLICATE / ERROR). Phase 2 adds a fourth: **ENRICHED**. The wizard shows enriched rows with a diff-style indicator ("source_ref: ∅ → ENDTOEND-XYZ") so the user understands exactly what would change before confirming. Results summary grows from "N imported · M skipped · K errors" to "N imported · M skipped · P enriched · K errors".
- **D-28c (source_format on enriched rows):** `transactions.source_format` continues to record the *creating* format. The format(s) that subsequently enriched the row live only in `enriched_from`. Rationale: keep the primary column stable and queryable for "rows from format X" while preserving the multi-format history off to the side.

### Adapter Implementations

- **D-25:** **MT940 parser is hand-rolled** in pure PHP. MT940 is line-based (tag:content), parseable in a few hundred LOC; the hard part is the bank-specific `:86:` narrative decoding, which library-supplied engines often misread for ASN anyway. New module-internal class `Modules\Ingestion\Internal\Adapters\Asn\AsnMt940Adapter` implements `SourceAdapter` and re-uses the Phase 1 `HeaderSniffer` pattern (extended with an MT940 signature). No new composer dependency.
- **D-26:** **CAMT.053 parser uses `genkgo/camt` `^2.10`**. Mature ISO 20022 library, handles the CAMT.053 sub-versions ASN exports (001.02 / 001.03 / 001.08). New module-internal class `Modules\Ingestion\Internal\Adapters\Asn\AsnCamt053Adapter` implements `SourceAdapter`, consuming the library's typed Statement/Entry/TxDtls objects and yielding `SourceTransactionDto` instances. `composer require genkgo/camt` is added in this phase. The transitive `moneyphp/money` dependency it pulls in is acknowledged in STACK.md and does not conflict with the project's `brick/money` usage.
- **D-27:** **MT940-specific counterparty pre-normalisation** runs BEFORE the shared `FingerprintComposer::normalize` step. The MT940 adapter (or its Normalize-stage extension) strips GVC transaction-type codes, BIC prefixes embedded in `:86:` text, `/REMI/` and `/NAME/` and similar SEPA narrative markers from the raw counterparty string before handing the cleaned form to the shared normaliser. This raises the MT940-vs-CAMT same-period dedup rate without changing the shared normaliser used by every other source.

### Upload Wizard Surface

- **D-29:** The wizard's source-format dropdown grows from one option ("ASN CSV") to **three**: "ASN CSV", "ASN CAMT.053 (XML)", "ASN MT940". The Livewire `UploadWizard` validator changes from `'in:asn-csv'` to `'in:asn-csv,asn-camt053,asn-mt940'`. Each format declares its own `HeaderSniffer` signature (CAMT: XML root namespace match; MT940: leading `:20:` / `{1:F01...}` block) so a wrongly-declared upload fails fast with a user-readable message before the parser runs.
- **D-29a:** No file-type auto-detection (ING-07 still applies project-wide). The dropdown remains the source of truth; `HeaderSniffer` validates the declared format.
- **D-29b:** New entries in `SourceAdapterRegistry` for `asn-camt053` and `asn-mt940`, wired in the `IngestionServiceProvider`. The registry stays the public surface — nothing else in the import pipeline knows which adapter is in use.

### Statement-Level Metadata

- **D-30:** CAMT.053 and MT940 carry **statement-level data** (opening/closing balance, statement number, statement period start/end, IBAN owner) that is currently discarded by the CSV adapter. Phase 2 captures it into a new `statement_summaries` table (one row per import_run when the source carries it), with FK to `import_runs`. Used in this phase only for an in-app "statement coverage" view that proves to the user the imported file actually covered the period it claimed — defends against silently dropping a transaction because of a malformed sub-block. Phase 5 forecasting may build on it; CSV imports leave the row absent.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints (PHP 8.5 + Laravel 13, DI-only, nwidart modules, Larastan level 10 strict, Pint, Pest, no frontend tests, localhost-only, calm aesthetic)
- `.planning/REQUIREMENTS.md` — Phase 2 covers ING-02 (CAMT.053) and ING-03 (MT940); also re-touches ING-06 (idempotency contract) because v3 fingerprint changes the meaning of "same transaction"
- `.planning/ROADMAP.md` §"Phase 2" — Goal + three success criteria; explicit reference to `EndToEndId` / `AcctSvcrRef` as the dedup anchor

### Phase 1 Artefacts (read for continuity — same patterns apply)
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-CONTEXT.md` — Module split, DI-only, the preview-then-confirm wizard, idempotency philosophy
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-SUMMARY.md` (and per-plan summaries 01-01 … 01-07) — What actually shipped; especially `01-04-SUMMARY.md` (pipeline) and `01-05-SUMMARY.md` (fingerprint / dedup)

### Research (read before planning)
- `.planning/research/STACK.md` — `genkgo/camt ^2.10` rationale and the `kingsquare/php-mt940` discussion (kingsquare ruled out for Phase 2 in favour of a hand-rolled MT940 parser; see D-25)
- `.planning/research/PITFALLS.md` — Idempotency / fingerprint pitfalls; cross-format dedup is the canonical case the pitfall list anticipates

### Existing Source (read before extending)
- `Modules/Ingestion/Public/Contracts/SourceAdapter.php` — The contract every new adapter implements
- `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` — Target output shape (extend `rawPayload` per D-24)
- `Modules/Ingestion/Public/Services/HeaderSniffer.php` — Pattern to extend for CAMT / MT940 signatures
- `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` — Where new adapters register
- `Modules/Ingestion/Internal/Adapters/Asn/AsnCsvAdapter.php` — Reference adapter implementation (mirror its lazy-Generator style, exception handling, BOM-safe input)
- `Modules/Ledger/Public/Services/FingerprintComposer.php` — Where v3 lives; the doc comment already anticipates re-derivation when `NORMALIZATION_VERSION` bumps
- `Modules/Ledger/Database/Migrations/2026_05_12_010005_create_transactions_table.php` — Current `transactions` schema (composite UNIQUE on `user_id, fingerprint`, `normalization_version` column, `source_format` `varchar(32)`)
- `Modules/Import/Internal/Pipeline/Stages/FingerprintStage.php` — User-scoped fingerprint lookup; v3 must continue to scope by `user_id`
- `Modules/Import/Internal/Http/Livewire/UploadWizard.php` — The `'in:asn-csv'` validator that must extend to three formats (D-29)

### External Documentation
- `genkgo/camt` library docs (https://github.com/genkgo/camt) — Statement / Entry / TxDtls API; namespace handling for 001.02 / 001.03 / 001.08
- ISO 20022 CAMT.053 schema reference (https://www.iso20022.org/) — Authoritative spec; `EndToEndId` location and semantics
- SWIFT MT940 specification (https://www2.swift.com/knowledgecentre/publications/us9m_20240719/) — Authoritative tag reference; `:61:` and `:86:` field structure
- ASN Bank MT940 export documentation (user supplies the PDF / link when MT940 sample data lands)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets (from Phase 1 — extend, do not reinvent)
- **`SourceAdapter` contract** — Generator-based, lazy. New adapters implement `format(): string` + `parse(string $localPath, AccountResolver $accounts): Generator<int, SourceTransactionDto>`. Phase 1 stack proves the pattern at scale.
- **`SourceAdapterRegistry`** — Add `'asn-camt053' => AsnCamt053Adapter::class` and `'asn-mt940' => AsnMt940Adapter::class` in `IngestionServiceProvider::register()`. No new public API.
- **`HeaderSniffer`** — Extend with two new signatures. For CAMT: leading XML declaration + namespace match against the ISO 20022 CAMT.053 family. For MT940: first non-blank line tag matches `^:20:` or a SWIFT block-1 `{1:F01...}`. UTF-8 BOM stripping logic already exists; reuse it.
- **`SourceTransactionDto`** — Already shaped for what Phase 2 needs (booked / posted / value date are distinct fields; currency + amountMinor; sourceRef + rawPayload). The CAMT adapter populates `rawPayload['sepa']` per D-24. No DTO shape changes.
- **`ImportPipeline` (Parse → Normalize → Fingerprint)** — Adapter-agnostic by design. The fingerprint version bump and the enrichment path live INSIDE the pipeline; the adapters do not change pipeline shape.
- **`FingerprintComposer`** — `NORMALIZATION_VERSION` already advertised as a version-bump signal in its doc-comment. v3 changes the tuple in `compose()` and bumps the constant. The composite UNIQUE on `(user_id, fingerprint)` does not change.
- **`UploadWizard` Livewire component** — Format dropdown + validator are the only Phase-1 surfaces that need extending. The flow itself (validate → preview → confirm) is reused unchanged.

### Established Patterns (continue without deviation)
- **DI-only** — no `auth()` / `Auth::user()` / global helpers / facades. Larastan custom rule enforces this in CI; new code must not introduce regressions.
- **`Public/` vs `Internal/` boundary** — every adapter lives under `Modules/Ingestion/Internal/Adapters/<bank>/`. DTOs / contracts / services that the Import module touches stay under `Public/`. Cross-module imports of `Internal/*` are rejected by the Larastan boundary rule.
- **Lazy generators in adapters** — no whole-file-in-memory parsing. CAMT XML uses `genkgo/camt`'s streaming interface (or SAX wrappers if its API is eager) so multi-year imports do not blow heap.
- **Integer-cent arithmetic** — every amount path stays on integer minor units; no float anywhere. `brick/money` for any cross-currency or display arithmetic.
- **Pest test layout** — per-module `tests/Unit` + `tests/Feature`. Adapter tests live next to the adapter (`Modules/Ingestion/tests/Unit/Adapters/Asn/AsnCamt053AdapterTest.php`). Snapshot tests via `spatie/pest-plugin-snapshots` for parser outputs (a known sample CAMT/MT940 file → known canonical DTO stream).
- **Idempotency in the UI** — the preview wizard surfacing row states is the user's confidence loop; D-28b extends that surface to ENRICHED.

### Integration Points
- **Schema migration ordering** — the v3 fingerprint re-derive must run BEFORE the `enriched_from` column add (or the migration must be transactional and re-derive in a single batch).
- **No queue infrastructure yet** — Phase 2 stays synchronous like Phase 1. Both CAMT and MT940 imports run inside a Livewire action; ASN files are small enough that this is acceptable. Async ingestion arrives in Phase 6.
- **Composer dependencies** — `composer require genkgo/camt` is the only new direct dependency. No removals.

### Risks Phase 2 Specifically Owns
- A migration that re-derives every existing transaction's fingerprint must handle large-history users (the project explicitly retains all history forever). Planner: prefer batched UPDATEs over a single transaction, with WAL-mode-friendly checkpoints, and abort-on-collision so no data is silently merged.
- `genkgo/camt`'s namespace handling — sample data must cover at least 001.02 and 001.08; a mismatch at this layer produces zero parsed entries, not an error.
- MT940 `:86:` ASN-specific narrative format — the hand-rolled parser needs a corpus of real ASN MT940 files to validate against; the user should provide samples before plan execution begins.

</code_context>

<specifics>
## Specific Ideas

- **ENRICHED as a first-class preview state** — the user cares deeply about the idempotency feedback loop (D-28b extends Phase 1's NEW/DUPLICATE/ERROR triad). When the wizard says "ENRICHED", the user must see exactly which fields would change ("source_ref: ∅ → ENDTOEND-XYZ" diff style).
- **`EndToEndId` is the chain-resolution anchor** — locking `source_ref = EndToEndId` here pays off in Phase 5 when PayPal-via-ICS chains need to join across formats. Do not weaken this contract just because some CAMT entries miss it.
- **MT940 is the legacy fallback, not the primary** — when CAMT and MT940 disagree about a period, CAMT wins per D-28's enrichment ordering. Plan / UI copy should reflect this priority.
- **Statement-level metadata as proof of coverage** (D-30) — the user has stated repeatedly that idempotency must be visible. Showing "Statement: balance €X.XX, period 01–31 March, 47 entries — all imported" is a continuation of that ethos. Defer the polish (separate page vs inline panel) to Claude's discretion within Phase 2.

</specifics>

<deferred>
## Deferred Ideas

- **`AcctSvcrRef` / `InstrId` / `TxId` as indexable columns** — preserved in `rawPayload` per D-24; promote to indexed columns only when Phase 5 chain resolution actually needs them.
- **PayPal Reporting API path (ING-09)** — Phase 4, not Phase 2.
- **ICS Cards / multi-currency display** — Phase 3.
- **Statement-coverage page polish** — a richer UI showing balance reconciliation, gaps in coverage, etc. — minimal version in Phase 2 (D-30), full UI in a later phase if needed.
- **Auto-detect uploaded file format** — explicitly rejected by ING-07 project-wide. Stay manual.
- **Migrating CSV imports to use `genkgo/camt` for compatibility** — out of scope; CSV adapter stays untouched.
- **`kingsquare/php-mt940` as a fallback parser engine** — explicitly rejected in favour of a hand-rolled MT940 parser (D-25). Revisit only if hand-rolled coverage proves insufficient against real ASN samples.

</deferred>

---

*Phase: 2-ASN Statement Coverage (CAMT.053 + MT940)*
*Context gathered: 2026-05-13*
