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
  in its own cluster. A keyed blind index would fix this and has not been built.
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

## The identity columns that are still plaintext, and what it would take to fix them

An audit of a live desktop database found that `accounts.iban`, `accounts.name`,
`accounts.slug`, `counterparties.slug` and `transactions.counterparty_normalized` are all
readable with no key while the UI reports encryption **On**. `counterparty_normalized` is the
worst of them: sixteen cleartext merchant names sitting beside sixteen ciphertext
`counterparty_name` values that say the same thing, joined to plaintext amounts and dates.

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
- **`accounts.name`** is the only one of the five with no equality predicate. It is
  encryptable, at the cost of ten `ORDER BY` sites that would silently sort by ciphertext
  bytes, roughly fifteen display sites, a three-way merge that compares against a plaintext
  baseline, and a `PROJECTION_COLUMNS` entry. The pattern to follow is
  `CounterpartyIndexQuery`, which orders by `id` in SQL and `usort()`s after decrypting.

### Why a keyed blind index is not a drop-in for `counterparty_normalized`

Replacing the plaintext with an HMAC under the group data key preserves equality and
uniqueness in principle. It does not survive contact with this keyring:

- **There is no rotation-stable key.** `GdkRotationService::rotateAndRevoke()` appends a new
  epoch and re-keys nothing already stored; reads stay correct only because decryption tries
  every epoch. An HMAC has no such fallback — the same merchant would hash to different bytes
  either side of a rotation. Epoch ids are random, and a joining device receives epoch wraps
  one at a time in arrival order, so there is no locally derivable "anchor epoch" either.
- **The key is not held at every write.** `SensitiveColumnCodec` passes values through
  unchanged when the app-lock is locked or the keyring is absent, and
  `OpLogValueProjector::reencryptForProjection()` does the same on replay — which is the
  ordinary state of the sync daemon. For an AEAD column a mixed plaintext/ciphertext column is
  tolerable. For a column inside a uniqueness index it means the same statement stores two
  different values under two different lock states.
- **The fingerprint is re-derived from the stored column.**
  `FingerprintRederiveService::buildCanonicalFromRow()` and
  `Migration\Internal\Pipeline\EntityChangeApplier` both read
  `transactions.counterparty_normalized` back out and recompute
  `FingerprintComposer::compose()` over it. An HMAC is one-way, so either the fingerprint has
  to be computed over the HMAC everywhere — dragging the keyring into a console command that
  has no session — or the plaintext has to be recovered by re-normalising the decrypted
  `counterparty_name`, which needs the same key. Getting this wrong does not fail loudly: it
  changes the fingerprint, and re-importing a statement doubles the ledger.
- **Some consumers need the plaintext, not equality.**
  `PaypalFundingResolver::fuzzyMatch()` runs a Levenshtein similarity over two normalised
  merchant names. No keyed hash can preserve that; the merchant term of the chain score would
  collapse to zero for every pair. `ExpenseSeriesDetector` and `IncomeSeriesDetector` fall
  back to writing `counterparty_normalized` into `recurring_series.detected_name`, which is
  rendered on the recurring review screen — a hex digest would go on the screen.
- **The blast radius is cross-table.** `merchants.normalized_name` carries
  `unique(user_id, normalized_name)` and is joined to this column by `RuleEvaluator` on the
  import hot path, comparing a stored value against a freshly computed plaintext one.
  `recurring_series.cluster_counterparty_key` holds a verbatim copy, and
  `SeriesEntryPlacer` matches that copy against `counterparties.slug`. All of them would have
  to move together, under one key, in one migration.

A blind index remains the right answer. It needs a dedicated per-user key minted at enable
time, stored in the keyring file alongside the epochs, never rotated, and fanned out on
pairing exactly as an epoch is — plus a decision about what a write does when that key is not
held, since passing plaintext through is what breaks dedup.

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

## See also

- [Removing a device: revoke, rotate, fan out](device-removal-and-epoch-rotation.md) — where
  epoch keys come from and why they are never discarded.
- [Sync architecture](architecture.md) — the class-by-class map of the codec, casts and
  enable-time migration.
