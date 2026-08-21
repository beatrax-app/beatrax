# `Tax` — architecture

The `Tax` module lets a user tag any transaction (or, for a split
transaction, any individual leg) as tax-deductible or as income,
optionally against a per-country deduction category with a note and a
year override, then rolls those tags up into a per-year cockpit
(`/tax`) and CSV/PDF export.

## Year resolution and the override

Every query resolves the *effective* tax year as
`COALESCE(tag.tax_year_override, CAST(strftime('%Y', t.booked_at) AS
INTEGER))`, so a manually-overridden tax year always takes precedence
over the transaction's booked date. Every read is scoped to the tagging
user via `where('tag.user_id', $userId)` as the first filter on every
query — a structural ownership guard, not a defense-in-depth
afterthought. [Tax year resolution](tax-year-resolution.md) covers the
override window, the seasonal default year, and why an empty year is a
header-only CSV rather than an error.

## Leg-aware amounts and the supersession policy

A tag can apply to a whole transaction or to a single leg of a split
transaction (`tax_transaction_tags.transaction_split_id` NOT NULL). A
leg-scoped tag always reports the leg's own amount, never the whole
parent's, and once a transaction has any leg-scoped tag its
whole-transaction tag row is excluded from every result — not deleted,
just no longer surfaced. [Tax year resolution](tax-year-resolution.md)
works through the arithmetic; [the tag write
contract](tag-write-contract.md) covers the supersession rule and the
uniqueness indexes behind it.

The category name shown for a leg-scoped tag always resolves from
`tag.deduction_category_id` (the tax deduction category), never from
the leg's own `category_id` (the spend category on
`transaction_splits`) — these are distinct concepts that must not be
conflated.

## CSV / PDF export

`TaxCsvExporter` reads exclusively from `TaxYearQuery::forUser()`, so
the exported CSV and the on-screen cockpit share the same
COALESCE year-override resolution and can never diverge; the export is
therefore automatically user-scoped by construction. The CSV emits a
fixed, tested 16-column order (`tax_year`, `booked_date`, `account`,
`counterparty`, `counterparty_iban`, `description`,
`deduction_category`, `note`, `settled_eur_amount`, `original_amount`,
`original_currency`, `transaction_type`, `transaction_id`,
`source_format`, `import_run_id`, `fingerprint`) — an audit-extra shape
richer than the on-screen cockpit, meant to be opened directly by an
accountant. Every cell is passed through `League\Csv\EscapeFormula` to
mitigate spreadsheet formula injection (a cell starting with `=`, `+`,
`-`, `@`, tab, or CR is prefixed with a single quote), since
descriptions/counterparties/notes are free text. Money values are
formatted via `number_format` from minor-unit integers — this is
presentation-layer string formatting only, never arithmetic; no
monetary calculation in this class uses floats.

`TaxPdfRenderer` uses dompdf v3 with `isHtml5ParserEnabled=true`,
`isRemoteEnabled=false` (local-only app; no remote CSS/image fetches),
and `defaultFont=Helvetica` (bundled, no network fetch). The PDF Blade
template (`tax::pdf.export`) is CSS 2.1 table-only layout — no
Tailwind/Flexbox/Grid, since dompdf's CSS 2.1 engine doesn't support
them — and every dynamic value uses Blade's `{{ }}` auto-escaping to
prevent XSS injection from free-text notes.

## Read-side decryption

The tax cockpit, CSV export, and PDF export all read
`description`/`counterparty display name`/`counterparty iban`/`note` —
every one of these is ciphertext at rest once encryption is enabled
for the user. `TaxYearQuery` decrypts each field on read via
`SensitiveColumnCodec`; the decrypt is a pass-through no-op when
encryption is not enabled.

## Tag / untag write contract

`TagTransaction::execute()` checks transaction ownership (404 on
miss), category ownership when a category id is given (404 on miss),
and — when a leg-scoped tag is requested — that the leg belongs to
both the given transaction and user (404 on a forged/cross-user leg
id). `tax_year_override`, when given, must fall within the current
year ±10 or the call throws `InvalidArgumentException`.

Re-tagging is idempotent and non-destructive: a bare re-tag with an
all-null payload leaves the existing category/note/year-override
untouched, and when *any* of the three payload fields is non-null all
three are rewritten together as a whole-payload upsert, never a
per-field patch. [The tag write contract](tag-write-contract.md)
explains what that costs a partial caller, and why the whole-tx
uniqueness backstop is a second, partial index.

An optional trailing `$transactionSplitId` scopes a tag/untag to one
leg of a split transaction instead of the whole transaction; it
defaults to null so every existing unsplit caller is unaffected, and
`field_provenance.tax_tag` always stamps the parent transaction (that
column lives only on `transactions`) regardless of leg-scoping.

`UntagTransaction::execute()` is fire-and-forget: it silently no-ops
when the tag doesn't exist or belongs to a different user (0 rows
deleted, no exception), matching the project's lifecycle-no-op
convention. Both actions optionally re-index the transaction via the
nullable `SearchIndexWriterContract` injection (a no-op when the
Search module is absent) so the tax note stays searchable/removed;
the writer itself re-verifies ownership from the passed actor id.

## Cross-module country selection

`TaxCountrySetup` exposes "which countries can be chosen" and "select
a country for a user" to other modules (the Onboarding setup wizard's
tax-country step) without letting them reach into
`Modules\Tax\Internal` — the module-boundary arch test forbids
cross-module `Internal` imports. `selectCountry()` mirrors
`TaxSettingsSection::setTaxCountry()`'s behavior exactly: codes
outside the allow-list are silently ignored, the corpus seed is
additive, and `users.tax_country_code` is persisted last so a corpus
failure never leaves a country set without its categories.

## Category writes

`TaxCategoryWriter::seedFromCorpus()` is INSERT-only on
`(user_id, corpus_key)` — an already-seeded corpus key is skipped so a
user's rename of a corpus-seeded category survives a later corpus
update, and a user-created category with the same name wins over a
corpus entry rather than raising a duplicate-name error. Every
category mutation (`rename`/`archive`/`unarchive`) is user-scoped and
throws `NotFoundHttpException` (never a distinguishing error) on a
cross-user category id, matching the same 404-not-403 posture used
throughout the module.

## Badge lookup: whole-transaction vs leg-aware

`TaxTagQuery::forTransactionIds()` is whole-transaction only — it
filters to `transaction_split_id IS NULL`. Callers that need leg-aware
state use `forTransactionIdsWithLegs()` instead, which keys its result
map by `"{transactionId}:{transactionSplitId}"` for a leg-scoped tag or
`"{transactionId}:whole"` for a whole-transaction tag. Both methods
issue exactly one `whereIn` query for the full batch, never one per
row, and treat absence from the result map as "untagged." [The tag
write contract](tag-write-contract.md) explains what dropping the
whole-transaction filter breaks, and why the breakage is silent.

`summaryForUser()`'s `totalMinor` is the deductions total only
(non-income rows), while `count` covers every tagged item regardless of
type — the two deliberately describe different sets of rows, as
[tax year resolution](tax-year-resolution.md) sets out.

## Year cockpit (`/tax`)

`TaxPage`'s seasonal year default resolves January-April to the
previous year and May-December to the current year (matching the
Dutch `aangifte` filing season) when no `?year=` query param is
present; the `#[Url]` attribute makes the resolved year deep-linkable
and back-button-safe. The "first visit" guided empty state is driven
by whether `users.tax_country_code` is set (read via the raw query
builder, not the Eloquent model, since the column isn't typed on
`User` — `TaxSettingsSection` writes it via `DatabaseManager::update()`
directly). Both `exportCsv()`/`exportPdf()` pass only the acting
`CurrentUser` to the user-scoped exporter/renderer, and every action
re-checks authentication as a defense-in-depth fallback behind the
route group's `auth` middleware.

## Settings country selection

`TaxSettingsSection` (the Settings page's Tax section) restricts
country selection to the `TaxCountry` enum — 33 lowercase ISO 3166
alpha-2 codes, one per corpus file under `resources/corpus/tax/`; any
other code is silently ignored. Switching country is
additive — it seeds the new country's corpus categories via
`seedFromCorpus()` (INSERT-only, never deletes) and never removes
existing categories or tags from a previously-selected country.

## HandlesTaxTagging trait

`Public/Http/Livewire/Concerns/HandlesTaxTagging` is the shared
tag/untag/category/batch-tag surface every transaction-row Livewire
component embeds. All collaborators arrive as method-parameter DI (no
constructor DI — the Livewire strict-rules prohibition), and event
wiring uses `$dispatch`/`#[On]` so the badge works from deeply-nested
row partials without direct method calls.

**Reconciled lock.** Every write path (`tagTransaction`,
`saveTaxCategory`, `untag`, `applyBatchTag`) checks
`TransactionStatusQuery::isReconciled()` first and warns-without-writing
via a shared toast — tax classification is exactly what a reconcile is
meant to freeze, and the whole-transaction tag path honors the same
lock the Ledger-side leg toggle does.

**Batch-suggestion banner.** After a one-tap tag, if the counterparty
has ≥2 other untagged transactions in the same year, a banner offers to
tag them all. The category, note and year it applies are snapshotted
onto the suggestion at `saveTaxCategory()` time, *before*
`closePicker()` wipes the live picker state.
[The batch-tag suggestion](batch-tag-suggestion.md) sets out that
ordering requirement, the `array_key_exists()`-not-`??` rule the
snapshot depends on, the trigger-row year keying, and the
reconciled-candidate filter behind the "tagged N more" count.

**Pitfall guard.** `taxTagStateFor()` issues exactly one query via
`TaxTagQuery::forTransactionIds()` for the whole batch, never one per
row.

## Module boundary

`Public/Services/TaxYearQuery` is a thin facade over
`Internal/Services/TaxYearQuery`, which carries the full query logic
and is only reachable from inside the module. The same
facade-over-internal shape is used for `TaxCategoryWriter`,
`TaxCsvExporter`, and `TaxPdfRenderer` — each Public class is the
singleton `TaxServiceProvider` binds and constructs a fresh internal
instance per call, so external consumers (TaxPage, exporters, the
year-switcher, other modules' tax-tagging surfaces) never reach into
`Modules\Tax\Internal\*`.

`Public/Http/Livewire/Concerns/HandlesTaxTagging` is the shared trait
any Livewire component embeds to get tax-tag state + the tag/untag
wire actions without duplicating the query/action wiring — it lives
under `Public/` (not `Internal/`) specifically so other modules'
Livewire components (e.g. the counterparty profile, the cash book, the
transactions list) can `use` it across the module boundary.
