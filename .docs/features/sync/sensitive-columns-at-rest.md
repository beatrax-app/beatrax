# Which columns are encrypted at rest, and why the rest are not

`SensitiveFieldRegistry::columns()` is the single list of `(table, field)` pairs that both
encryption hooks — the op-log writer and the projection-column codec — treat as content
requiring Group Data Key encryption at rest. Nothing else consults a second list, and a new
entry lands there only after an explicit scope decision.

The interesting content of that list is not what is on it. It is why the obvious "encrypt
everything" answer is wrong, and what the exceptions cost.

## The rule

A column is encrypted when it holds **content a human wrote or a bank sent** — free text,
names, IBANs, raw payloads. It is left in plaintext when the database itself has to reason
about the value.

Currently encrypted:

- `transactions.note`, `.description`, `.counterparty_name`, `.counterparty_iban`,
  `.raw_payload`
- `counterparties.display_name`, `.merchant_name`, `.iban`
- `tax_transaction_tags.note`, `transaction_splits.note`
- `notifications.title`, `.body`, `.params`, `.trigger_type`

## Why money columns are not on the list

`transactions.amount_minor`, `settled_amount_minor` and `fx_rate_used` are deliberately
excluded. At least eleven query classes run SQL-side `SUM()` and `GROUP BY` over them, and
SQLite cannot aggregate ciphertext. Encrypting them would mean loading every row of a
multi-year ledger into PHP to add up a month's spending — the balance, the budget bars and
every report would stop being a query and start being a full-table decrypt.

That is the general shape of the boundary: a column the database aggregates, joins on, or
filters by cannot be random-nonce ciphertext, because ciphertext is different bytes every
time it is written.

## Why some notification columns stay plaintext

`notifications` carries an encrypted body but a plaintext skeleton, and the split is load
bearing:

- `id` is matched in deduplication `WHERE` clauses. It is also, unusually, a deterministic
  digest rather than an autoincrement surrogate — see
  [How a replay decides what the row should say](op-log-merge-rules.md).
- `user_id`, `created_at`, `read_at`, `dismissed_at` and `state` drive pruning and the unread
  count. Those run without the app-lock key held; encrypting them would make the badge on a
  locked app impossible to compute.

## The knowingly-accepted exceptions

Three columns hold decrypted values on purpose. Each is a reviewed decision, not an
oversight:

- **`recurring_series.cluster_counterparty_key`** stores a decrypted IBAN or description
  clustering key verbatim. Random-nonce ciphertext cannot be a stable `WHERE` key — the same
  input encrypts to different bytes each time, so grouping on it would put every occurrence
  in its own cluster. The **expense** path now carries the keyed counterparty blind index
  instead; the income path still holds a decrypted IBAN, and that half remains open.
- **`migration_import_baseline.baseline_value`** snapshots a plaintext value so the
  three-way merge resolver can compare against it.
- **`pending_enrichment_conflicts.stored_value` / `.incoming_value`** hold decrypted values
  of a held receipt-enrichment conflict until the user resolves the prompt, so the prompt
  never renders ciphertext.

Deferred rather than decided: `counterparties.metadata` and `saved_reports.definition`.

## How the encryption is bound to its epoch

`OpLogFieldCrypto` is XChaCha20-Poly1305 IETF AEAD, framed as
`base64(nonce || ciphertext)`. The associated-data argument is the epoch-binding channel:
callers pass `"{table}:{pk}:{field}:{epochId}"`, so relabelling the stored epoch tag
invalidates the authentication tag. That is defence in depth alongside the Ed25519 signature
that already covers the whole op-log entry.

Decryption returns `false` — never a throw, never garbage — on invalid base-64, a blob too
short to contain a ciphertext, or an authentication failure. Callers must use a strict
`=== false` check; `!$result` would treat a legitimately empty plaintext as a failure. A
false result routes to quarantine.

Reads are rotation-safe: the projection codec tries **every** epoch in the keyring, and
returns the raw stored value with `decrypted: false` when none verifies, so a legacy
plaintext value from before encryption was enabled still reads correctly. That is also how
the op-log backfiller distinguishes the two cases — a value handed back untouched is an
ordinary pre-encryption row, whereas the codec *blanking* it means it held ciphertext no
epoch in the keyring opens, and capturing that would put an unreadable value on the wire.

## The guard that catches a query written against ciphertext

A `WHERE counterparty_iban = ?` against an encrypted column does not fail. It returns no
rows, silently and forever, because the stored bytes are a fresh random-nonce ciphertext and
the predicate is plaintext. The same is true of an `ORDER BY` that sorts by ciphertext, a
join on one, or a raw `update()` that writes plaintext straight into an encrypted column.
None of these raise; they just quietly stop being right.

`SensitiveColumnPredicateGuardTest` is a source scan standing in for the type system that
would otherwise have caught it. It walks every production file under `Modules/` — skipping
`tests/`, `Database/` and `Resources/` — and looks for each bare column name from
`SensitiveFieldRegistry::columns()` appearing in one of the shapes that only makes sense
against plaintext: a `where`/`whereIn`, an `orderBy`/`groupBy`, a join `->on(...)`, a
`whereRaw(... LIKE ...)`, a raw `json_decode(...)`, or a `'column' =>` key inside an
`update()`/`insert()` array. A file that mentions any codec marker — `SensitiveColumnCodec`,
`decryptValue`, `encryptValue`, `encryptAttrs` — is assumed to be routing through the codec
and is skipped wholesale.

It is a substring scan, so it is coarse in both directions. It matches a *bare* column name,
which means `accounts.iban` trips on the registry's `counterparties.iban` entry even though
`accounts.iban` is plaintext and always has been. And the codec-marker check is per file, so
one decrypting read in a file exempts every other query in it. An AST rule modelled on
`app/PhpStan/Rules/BoundaryRule.php`, matching a `MethodCall` node with a sensitive string
literal argument, would be precise where this is not; it has not been built.

### Adding an allowlist entry

`sensitive-column-guard-allowlist.php` maps a repo-relative path to the reason that file is
safe. Three reasons are legitimate:

- a **different table** happens to share the bare column name, as with `accounts.iban`;
- the predicate is a `whereNull`/`whereNotNull` presence check, which works on ciphertext
  because it never compares the value;
- the file already decrypts by a route the marker list does not recognise.

Anything else is the bug the guard exists to find, and the fix is the query, not the list.
Write the reason so the next reader can re-derive the judgement without opening the file.

## The guard that catches a column rendered as ciphertext

The predicate guard above is a source scan, and this class of defect is not visible to one.
Three static designs were tried against the real bug and all three fail:

- scanning Blade for the registry's column names drowns in `title`, `body`, `note`,
  `description` and `params` — a Blade `$title` is a page title, and 28 files match that word
  alone;
- flagging a file that names a sensitive column and carries no codec marker gives a **false
  negative on the exact defect**, because the pre-fix `CounterpartyTriageQueue` already carried
  three markers: it decrypted transaction descriptions a few lines below the counterparty
  columns it missed;
- diffing selected columns against decrypted columns per file reports `selected=[]`, because
  the offending query selects `*` through the raw builder and calls `Model::hydrate()`, so the
  leaking columns are never named in the source.

`RenderedCiphertextGuardTest` is behavioural instead. It seeds recognisable plaintext, enables
encryption through `EncryptionMigrationService::migrate()` — the production enable path, not a
hand-rolled fixture — then renders twenty-two surfaces over the ciphertext that produces and
asserts that the exact bytes stored in the database do not appear in what reaches the browser.

Twelve are full HTTP renders: `/counterparties/triage`, `/counterparties`,
`/counterparties/{slug}`, `/community/mystery-merchants`, `/transactions`, `/transactions/{id}`,
`/uncategorized`, `/notifications`, `/recurring`, `/recurring/review`, `/recurring/series/{id}`,
`/tax`, `/reports`, `/cash`, `/calendar` and `/imports/{id}/preview`. Six are components with no
route of their own, mounted by alias so no module boundary is crossed: the ⌘K palette, the chain
drawer, the receipt-conflict toast, the rule-form modal, the rename-counterparty popover, and the
alias match preview on `/settings/aliases`.

For a Livewire component the assertion runs over `html()` **and** the public properties. A leaked
value reaches the browser inside the `wire:snapshot` payload whether or not the view prints it,
and the palette is a hidden container whose results live only there — checking the rendered HTML
alone would have called it clean.

The census is built from `SensitiveFieldRegistry::columns()` and
`SensitiveFieldRegistry::blindIndexColumns()`, so a column added to either accessor is covered on
every one of those surfaces for free, provided the fixture writes a row in its table.

Two surfaces are deliberately excluded, and the reason is the same for both: they render nothing
that comes from a registered column. The import preview's **rows** are parsed from the uploaded
file and held in `PreviewCache`; `TaxSummaryCard` renders totals. Covering either would add a
case that cannot fail. The import preview is in the list above for a different reason, below.

Two things about it are load bearing and easy to remove by accident.

**The precondition.** Decrypting plaintext is a documented no-op, so a fixture that quietly
failed to encrypt would let a completely broken read path pass on every screen at once. The
first case reads every registered column back out and requires `decrypted: true` before a
single screen is rendered.

**The positive half.** The absence assertion cannot see a value a view masks, truncates or
reformats first — the triage card puts the IBAN through a mask that prints six characters of
it, which is exactly why the shipped bug read `7F · ·· HUX5 ···· ···· ==` rather than a
recognisable blob. Each screen therefore also asserts the plaintext it exists to show, in the
form it shows it. That half doubles as the check that the fixture still reaches the screen,
since an empty state passes an absence assertion perfectly.

The blind index rides along in the same census. All **three** of its columns do —
`transactions.counterparty_normalized`, `merchants.normalized_name`, and the expense half of
`recurring_series.cluster_counterparty_key`, which
`CounterpartyKeyBackfill::convertExpenseSeries()` converts and which is the one
`CounterpartyKeyHasOneProducerTest` omits from its write markers. They are keyed digests rather
than AEAD and are deliberately absent from `SensitiveFieldRegistry`, so a guard reading that
list alone has no opinion about a digest on a screen — which is the same defect wearing a
different mechanism.

`CounterpartyKey::NONE` — `_no_counterparty` — is in the census beside the digests. The sweep
stores it verbatim on purpose, because it records the *absence* of a counterparty rather than
naming one, which is the right call for the two guards that compare against it. It is still a
machine token in a column the reader must never be shown, and it is the one blind-index value
`looksDerived()` answers `false` for, so a rule written as "reject what looks derived" lets it
straight through. That is not hypothetical: `MerchantDisplayName::forStoredKey()` returns null
only when `looksDerived()`, so a cluster of un-named expenses writes `detected_name =
'_no_counterparty'` and the recurring review screen renders it.

The same census run also asserts the crypto layer's own vocabulary is absent —
`BlindIndexCodec`, `SensitiveColumnCodec`, `OpLogFieldCrypto`, a `Modules\Sync\` namespace
fragment. That is a different shape from a leaked value and it has a live instance:
`ImportPipeline` catches `Throwable` around the normalise stage and puts `$e->getMessage()`
into the preview row, so `BlindIndexKeyUnavailableException` is printed to the reader once per
row, naming an internal class and their own user id where "unlock the app and try again"
belongs. The same file already routes its *log* message through `MessageNamesNoUserData`; the
preview row does not, and a screen needs the stricter rule of the two.

### Why the two column lists stay separate

`SensitiveFieldRegistry::columns()` is not a description, it is an instruction: `encryptAttrs()`,
`decryptRow()`, `OpLogValueProjector` and the enable-time sweep all *act* on every entry. Adding a
blind-index column to it would put AEAD over the one column the database has to match on, which is
the failure this whole design exists to avoid. So the two lists must not merge.

They should still be reachable together, because the readers that want both are guards, audits and
the question "could a human read this value". The shape that satisfies both is a **third accessor**
beside `columns()` and `knowinglyPlaintext()`, and it now exists: `blindIndexColumns()` returns the
three columns with the domain each derives under, and `blindIndexSentinel()` names the one value
they hold in the clear. `columns()` is unchanged and is still the only thing the codec consults. A
guard composes the two explicitly rather than one list quietly acquiring a second meaning.

The render guard reads both accessors and pins that they stay disjoint, which fails loudly if a
blind-index column is ever added to the registry — the change that would put AEAD over a column
the database matches on, and leave the ledger silently failing to deduplicate.

### What the enable-time sweep does not reach

`notifications` is in `SensitiveFieldRegistry` and is **not** in
`PreMigrationSnapshot::PROJECTION_COLUMNS`. `EncryptionMigrationService` calls
`encryptProjectionTable()` for `transactions`, `counterparties`, `tax_transaction_tags` and
`transaction_splits`, and for nothing else, so a user who already had notification rows when
they turned encryption on keeps those rows in plaintext on disk indefinitely, while the UI
reports encryption **On**. New notifications are fine — `NotificationWriter` encrypts on write
— and the op-log arm of the sweep covers `op_log_entries` for every registered field. It is
only the existing projection rows for that table that are never rewritten.

This also narrows a claim made further down this page: adding a column to the registry covers a
not-yet-enabled user *only when that column's table is already in `PROJECTION_COLUMNS`*. A new
table needs an entry there in the same change, or its history is left in the clear.

## The identity columns that are still plaintext, and what it would take to fix them

An audit of a live desktop database found that `accounts.iban`, `accounts.name`,
`accounts.slug`, `counterparties.slug` and `transactions.counterparty_normalized` were all
readable with no key while the UI reports encryption **On**. `counterparty_normalized` was the
worst of them — sixteen cleartext merchant names beside sixteen ciphertext `counterparty_name`
values saying the same thing — and it is the one that has since been fixed, by the keyed blind
index described in the next section. The rest are still plaintext.

Every one of those columns is a **matching key**, and that is why none of them can take the
AEAD treatment the rest of the list gets. Random-nonce ciphertext is different bytes every
time it is written, so an equality predicate against it returns no rows, a UNIQUE index over
it never collides, and a route parameter built from it resolves to nothing.

- **`accounts.iban`** backs eleven production equality predicates, including
  `EloquentAccountResolver` (the import-time statement-to-account match), `TransferPairer`,
  `ClassifyTransactionType`, `CounterpartyResolverService::resolveSelfAccount()`, and four
  separate duplicate-account existence checks. It also carries `unique(user_id, iban)` and
  feeds two `sha256` chain signatures. Encrypting it turns every import into "unknown IBAN",
  turns every own-transfer into a merchant, and makes the duplicate-account guard a no-op.
  The column is also `string(34)`, too narrow for `base64(nonce || ciphertext)`.
- **`counterparties.slug`** is the URL segment of `/counterparties/{slug}` and the key
  `CounterpartySlugResolver`'s collision walk predicates on. Encrypt it and every candidate
  reads "free", every counterparty takes the base slug, and the UNIQUE index that would have
  caught it never fires.
- **`categories.slug`** additionally has global rows with `user_id IS NULL`, and the codec
  keys on a user. There is no key to encrypt them under.
- **`accounts.slug`** carries `unique(user_id, slug)` and `AccountSlugResolver::isTaken()`
  walks collisions with `where('slug', …)->exists()`. Same shape as `counterparties.slug`:
  ciphertext reads every candidate as free and the UNIQUE index never fires.
- **`accounts.name`** is the only one of the five with no equality predicate. It is
  encryptable in isolation, at the cost of ten `ORDER BY` sites that would silently sort by
  ciphertext bytes, roughly fifteen display sites across forty-four files that reach
  `table('accounts')`, a three-way merge that compares against a plaintext baseline, and a
  `PROJECTION_COLUMNS` entry (`accounts` has none today, so a registry entry alone would not
  even sweep an existing user). The pattern to follow is `CounterpartyIndexQuery`, which
  orders by `id` in SQL and `usort()`s after decrypting.

  **But encrypting it in isolation is incoherent, not merely expensive.** `accounts.slug` is
  `Str::slug()` of `accounts.name` and provably cannot be sealed, so a sealed `name` leaves a
  readable copy of itself one column over — exactly the objection the audit raised against
  `counterparties.display_name` sitting beside a plaintext `counterparties.slug`. `accounts.name`
  and `accounts.slug` move together or not at all, and moving them together means a blind index,
  not an allowlist entry.

### The decision is recorded, not merely absent

`SensitiveFieldRegistry::knowinglyPlaintext()` lists these four columns with a one-line reason
each, so "not on the encrypted list" stops reading the same as "nobody looked". Three tests in
`SensitiveColumnPredicateGuardTest` hold the two lists honest against each other: they must be
disjoint, every column an allowlist reason leans on must appear in `knowinglyPlaintext()`, and
no allowlist reason may cite a column the registry has since started encrypting.

### The keyed blind index in `counterparty_normalized`

For a user with at-rest encryption enabled, `transactions.counterparty_normalized` holds
`HMAC-SHA256(blind-index key, "beatrax-blind-index:v1|counterparty-normalized|{userId}|
{normalised name}")` rendered as 64 lowercase hex characters, which fits the column's
`varchar(80)`. Equality and uniqueness survive exactly; the readable name does not.

#### Why the same merchant occupies two columns

`transactions.counterparty_name` and `transactions.counterparty_normalized` hold the same
merchant, under two different constructions, because they answer two different questions.

`counterparty_name` is **AEAD** — XChaCha20-Poly1305 with a fresh random nonce. It is
reversible with the key, which is what a screen and a Levenshtein comparison need, and it is
different bytes on every write, which is what makes it useless as a matching key: an equality
predicate never matches, a `UNIQUE` index never collides, and a `GROUP BY` puts every
occurrence in its own bucket. That is the general reason a column the database has to *reason
about* cannot be sealed.

`counterparty_normalized` is a **blind index** — a keyed one-way digest. It is deterministic,
so the database can compare, group and enforce uniqueness on it exactly as it did on the
plaintext, and it is irreversible, so holding the file buys nothing without the key. What it
cannot do is come back: nothing recovers a name from it, which is why every consumer that
needs readable text reads the AEAD column instead.

The digest is bound to the derivation domain, the user id, and the normalised name — and to
nothing else. Not the row, not the epoch, not the write. That is deliberate and it is the
whole difference from AEAD: two rows for one merchant must produce identical bytes, or the
index that dedups them stops working.

`BlindIndexCodec` is the keyed primitive. `CounterpartyKey` is the only thing that produces a
value for this column, and it is what the three producers call —
`Import\Public\Pipeline\NormalizeStage`, `CashBook`'s `RecordManualTransaction`, and
`Migration`'s `PromoteStagingToDomain`. A user who has never enabled encryption gets the
plaintext normalised name back unchanged, because every other column of theirs is plaintext
too and keying only this one would buy nothing.

The `_no_counterparty` sentinel is stored verbatim. It records the absence of a counterparty
rather than naming one, so keying it would conceal nothing while costing the two guards that
compare a stored value against it (`RuleEvaluator`, `MerchantMemoryWriter`).

`merchants.normalized_name` moves with it. `RuleEvaluator` and `MerchantMemoryQuery` compare
the two columns directly, so they share one derivation domain. No production code inserts a
`merchants` row — they arrive by op-log replay, carrying whatever the originating device
stored — so only the enable-time sweep and the demo seeder had to change.
`recurring_series.cluster_counterparty_key` is a copy of the same value on the expense path
and follows for free.

#### The four constraints this design has to satisfy

**There is no rotation-stable key.** True of epochs, and the reason the blind-index key is not
one. It is separate key material in the same KEK-wrapped keyring file, minted once beside the
first epoch and never rotated. `GdkRotationService::rotateAndRevoke()` appends epochs and does
not touch it. The consequence is stated plainly below.

**The key is not held at every write.** This dissolved once the writes were separated into the
ones that *compute* the value and the ones that only *copy* it. Only three sites compute, and
every one of them is an authenticated Livewire request behind `AppLockMiddleware`: the import
wizard, the cash book, and the migration importer. There is no headless import path. Op-log
replay never computes — `OpLogValueProjector::reencryptForProjection()` passes a non-sensitive
column through unchanged, so the digest the originating device computed is what the peer
stores, and the sync daemon needs no key at all. That is also why this column is deliberately
**not** added to `SensitiveFieldRegistry`: it is already keyed, and AEAD over it would make it
unmatchable.

Where a value must be computed and the key is not held, `BlindIndexCodec::derive()` throws
`BlindIndexKeyUnavailableException` rather than passing the plaintext through. That is the
whole point: this column sits inside `transactions_fingerprint_uq`, and one statement stored
under two lock states would make a re-import a second ledger.

**The fingerprint is re-derived from the stored column.** Solvable purely by ordering, and it
was. `FingerprintComposer::compose()` treats `counterpartyNormalized` as an opaque tuple
member, and both re-derivers — `FingerprintRederiveService::buildCanonicalFromRow()` and
`Migration`'s `EntityChangeApplier` — echo the stored value back rather than re-normalising a
name. A hash of a hash is still stable, so as long as the key is applied at canonicalisation
every downstream derivation stays deterministic and needs no key.

**Some consumers need plaintext, not equality.** They now read the plaintext where it actually
lives. `counterparty_name` is encrypted but decryptable, and that is the source both of them
use:

- `PaypalFundingResolver::fuzzyMatch()` selects and decrypts `counterparty_name` for the
  Levenshtein term, and keeps the stored key for `signatureHash()` so earlier evidence still
  matches. Two digests of one merchant spelled two ways are as far apart as two unrelated
  merchants; the similarity would have collapsed below `FUZZY_MIN_CONFIDENCE` and the whole
  arm would have gone silently dead.
- The recurring detectors write a *displayed* name. `MerchantDisplayName::forStoredKey()`
  answers null when the key is a digest and neither `merchants.name` nor the decrypted
  `counterparty_name` knows a name, and the detector defers the series to the next sweep
  rather than putting a digest on the review screen. That is the same choice
  `DetectRecurringSeriesJob` already makes for undecryptable IBANs.

`IcsSettlementResolver` needed nothing: all four of its uses are equality on both sides.

#### Where the key lives, and why it cannot live anywhere else

The blind-index key is 32 random bytes in the keyring file, wrapped under the app-lock KEK,
alongside the epochs. It is never written to the database and never leaves that file except as
a sealed, signed wrap addressed to a confirmed peer.

The requirement that decides this is that the key must be **secret**. Merchant names are
low-entropy: an attacker holding the database file and the key could HMAC a dictionary of Dutch
merchants and match every row, and the exercise would be theatre. So the key cannot sit in
plaintext beside the data, which rules out a device-held key in a file the sync daemon can read
without a passphrase — the daemon's ability to read it is exactly the attacker's ability to read
it, because the threat model is someone holding the disk.

That pulls against "available at every write" only if every write must compute. It need not, and
does not. The two requirements are reconcilable precisely because the computing writes are the
interactive ones. Nothing is deferred and nothing is quarantined; the write that cannot compute
is refused, and there is no production path that reaches it.

#### Fan-out, and why it is not a new message type

A joining device receives the key through the existing `GDK_EPOCH_WRAP` channel with a
`key_role` of `blind_index` and an `epoch_id` of `0`, sent by
`GdkRotationService::fanOutAllEpochsToDevice()` after the epoch loop.
`GdkEpochWrapSignature::signingMessage()` appends the role term only when it is not the default,
so an epoch wrap signs byte-identically to one a build without roles produced, and such a build
rejects a role-bearing wrap on signature rather than adopting a blind-index key as an epoch.
`SyncWebSocketHandler`'s live-push filter keys on the message type, which is unchanged, so the
wrap rides the same session.

#### What happens when two devices hold different keys

`GdkEpochControlHandler` adopts an inbound blind-index key when this device holds none, or holds
one it has not yet keyed anything under. Once
`sync_encryption_state.counterparty_key_backfilled_at` is set it **keeps the local key and logs
an error**. Adopting at that point would leave every stored digest unmatchable by the value a
re-import computes, which is how a ledger doubles; the cost of keeping it is that merchant
identity does not match across the two devices, which is loud and recoverable. It mirrors the
rule `reconcileCollision()` already applies to an epoch id that local rows depend on.

The marker is set by `CounterpartyKeyBackfill` **only when the sweep actually converted a row**,
and that distinction is load bearing. A phone enables encryption during pairing, which mints it
a blind-index key of its own and sweeps a database with nothing in it. Marking that as "this
device has derived keys" would make it refuse the desktop's key seconds later, and the two would
never agree on a merchant again. `BlindIndexKeyDeliveryTest` pins it.

The residual case the rule does not cover: a device that enabled encryption on an empty
database, imported under its own key, and only then paired with a peer that had independently
enabled encryption. Both hold keyed rows, so the joining side refuses and logs. Recovering means
re-deriving one side's rows from the decrypted `counterparty_name`, which nothing does
automatically.

#### The sweep that converts existing rows

`EncryptionMigrationService` runs `CounterpartyKeyBackfill` as the last step inside the
enable-time transaction, and once more for a user who already had `current_epoch` set before
this column existed, on the first unlocked call to `migrate()`.

It rewrites `transactions.fingerprint` in the same statement as
`transactions.counterparty_normalized`. It has to: the fingerprint is a SHA-256 over a tuple
containing that column, so a row converted without it would no longer match its own re-import,
and `FingerprintStage` would classify the statement as new. The date fields go back through
`CarbonImmutable` before reaching the tuple so the result is byte-identical to what a fresh
import composes. `CounterpartyBlindIndexTest` pins both halves and fails on either alone.

Income series are skipped on purpose: `cluster_counterparty_key` holds a decrypted IBAN on that
path, recomputed from the transaction each sweep and never keyed, so converting it would leave
the lookup matching nothing.

#### The guard that keeps one producer

`CounterpartyKeyHasOneProducerTest` scans every production file under `Modules/` for a supplied
value for `transactions.counterparty_normalized` or `merchants.normalized_name`, and fails
unless the file names `CounterpartyKey` or is pinned as a pass-through with a written reason.
It is the same substring technique as `SensitiveColumnPredicateGuardTest`, and it is coarse in
the same direction: a file that mentions `CounterpartyKey` anywhere is assumed to be routing
through it.

It exists because a second producer fails silently. Nothing raises; the column simply holds two
forms of one merchant inside `transactions_fingerprint_uq`, and the statement that produced the
first re-imports as a second ledger.

#### What this does not fix

- **The full-text search index still holds the merchant name in the clear.**
  `transaction_search_docs.search_body` is `counterparty_name` + description + note, decrypted
  at write time so FTS5 can tokenise it, one row per transaction in the same file.
  `SELECT search_body FROM transaction_search_docs` recovers exactly what the blind index was
  meant to hide. [ADR-0018](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0018-amounts-plaintext-at-rest.md) records that shadow as knowingly accepted and
  names an encrypted-search design as the revisit; until that exists, this change removes one
  of two plaintext copies, not the leak. **The UI copy must not claim otherwise.**
- `merchants.name`, `recurring_series.detected_name` and `counterparties.slug` are readable
  names and stay readable. The first two are display columns by definition; the third is a URL
  segment.
- `ClusterKeyComposer` truncates each part to 60 characters, so `cluster_key` carries 240 bits
  of the digest rather than 256. Collision-free in practice, but a `cluster_key` can no longer
  be read back to the digest that produced it.
- `SeriesEntryPlacer`'s cluster-key-to-slug fallback only ever fired for single-token merchants
  where the normalised key happened to equal the slug. It can no longer fire for an encrypted
  user; those calendar entries lose their counterparty deep link and resolve through the
  occurrence link or not at all.
- A device removed from the group keeps the blind-index key, because it is never rotated. It can
  therefore confirm whether a given merchant appears in a database file it later obtains. It
  already held every one of those names in plaintext while it was trusted, and
  [ADR-0015](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0015-multi-master-p2p-sync.md) states that revocation is not a defence against a device that
  was trusted while it was listening.

### One thing that did not need encryption

`accounts.slug` used to end with the last six or eight characters of the IBAN, appended so two
accounts under one user could not land on the same `unique(user_id, slug)` row. That is the
rule `counterparties` already keeps — its migration says the slug is the kebab-cased display
name "and nothing else" because it reaches a URL — and `PrivacyDefaultsTest` enforces it there.
`AccountSlugResolver` now derives an account slug from the account name alone and separates
collisions with a numeric suffix, and
`2026_08_21_000002_strip_the_iban_tail_from_account_slugs` re-slugs the rows the old
generators wrote. It matches only the exact shape they produced — the name slug, a hyphen, and
a run that really is the tail of that row's own structurally valid IBAN — so `-ics-card`,
`-paypal`, `cash-7` and a plain `-2` are left alone. `accounts.name`, `.slug` and `.iban` are
all plaintext, so the migration needs no key and runs on a locked device.

### The re-key that a schema migration must never attempt

Adding a column to `SensitiveFieldRegistry` covers new writes and covers a user who has not
enabled encryption yet, because `EncryptionMigrationService::migrate()` sweeps
`PreMigrationSnapshot::PROJECTION_COLUMNS` at enable time. It does **not** cover a user who
already has `current_epoch` set: that method returns early, so their existing rows keep the
plaintext. Re-keying them needs the app-lock KEK, and a Laravel migration never holds one.
The safe shape is the one `migrate()` already uses — when the KEK is unavailable, return and
leave the data untouched, to be retried on the next unlock; never write over a value that
could not be read first.

### The allowlist entry that would quietly become a lie

`sensitive-column-guard-allowlist.php` exempts six files on the stated grounds that their
`where('iban', ...)` targets `accounts.iban`, "a plaintext column SensitiveFieldRegistry never
lists". The guard skips allowlisted files *before* scanning. The moment `accounts.iban` is
added to the registry, those six exemptions silently cover the six most dangerous predicates
in the codebase, and the allowlist honesty check cannot detect it — it only greps reasons for
`broken`/`TODO`/`FIXME`. Any change that lists `accounts.iban` must delete those six entries
in the same commit.

That is now enforced rather than merely written down. `SensitiveColumnPredicateGuardTest` pulls
the `{table}.{column}` pairs back out of each allowlist *reason* and fails if any of them has
entered `SensitiveFieldRegistry::columns()`, with an in-memory negative probe pinning that the
check really does catch all six. Promoting `accounts.iban` without deleting the exemptions goes
red on two tests, not zero.

### What the reader is told, and why the old sentence had to go

`/data-devices` used to caption the status row *"Your data is secured with your app-lock
passphrase."* — one clause, no qualification, next to a database file whose `accounts.iban`,
`accounts.name`, both `slug` columns and `transaction_search_docs.search_body` are readable with
no key at all. A privacy-motivated reader reasonably concluded their merchant history was
unreadable. It was not.

`sync::devices.encrypted_at_rest_scope` replaces it — a **new key**, so no locale can be left
behind still rendering the old promise, and the retired `encrypted_at_rest_help` is gone from all
26. It names both halves: what the passphrase covers, and that amounts, dates, the reader's own
account name and IBAN, and some merchant names elsewhere in the file are not covered. The
enable-encryption modal already made that disclosure; the status row is the surface a reader
actually returns to, so it has to make it too. Pinned by `DevicesAndSyncEncryptionUiTest`, which
asserts the row names what is *not* covered and asserts the unqualified sentence is absent.

## See also

- [Removing a device: revoke, rotate, fan out](device-removal-and-epoch-rotation.md) — where
  epoch keys come from and why they are never discarded.
- [Sync architecture](architecture.md) — the class-by-class map of the codec, casts and
  enable-time migration.
