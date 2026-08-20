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

## See also

- [Removing a device: revoke, rotate, fan out](device-removal-and-epoch-rotation.md) — where
  epoch keys come from and why they are never discarded.
- [Sync architecture](architecture.md) — the class-by-class map of the codec, casts and
  enable-time migration.
