# Counterparty retention and garbage collection

The [resolution chain](resolution-chain.md) creates a counterparty for
almost every transaction it sees, including a `type = 'unknown'` row
for anything it cannot place. That is the right trade at import time —
a row the user can triage beats a lost signal — but it means the
`counterparties` table accumulates one-off payees indefinitely. A
holiday car-hire firm in Portugal, a one-time private transfer, a
mistyped IBAN: each leaves a permanent row that clutters
`/counterparties` and the triage queue forever.

Deleting by age alone is not safe. Two different things make a
counterparty worth keeping, and they are independent: the row is *in
use* (transactions still point at it), or the user has *named* it (a
merchant alias anchors it). Either one on its own is enough.
`Modules\Counterparties\Internal\Jobs\CounterpartyGarbageCollectorJob`
is the daily per-user sweep that applies both.

## When it runs

`routes/console.php` registers a daily `counterparties.gc` schedule entry
running `counterparties:collect-garbage`, which walks every user in
batches of 100 and dispatches one job per user. It used to name 04:00
Europe/Amsterdam, an hour after the daily `db:backup` run, so a
freshly-pruned counterparty set was what the next morning's snapshot
captured — which still holds now that both run daily and the backup entry
is defined first. The hour itself had to go: the phone's background
runner takes a repeat interval and no wall clock, so a `dailyAt()` entry
never reached a device at all. See
[the mobile architecture page](../mobile/architecture.md#the-phone-runs-an-artisan-name-on-an-interval-and-nothing-else).

The job is `ShouldBeUniqueUntilProcessing` keyed on the user id, with
`uniqueFor()` of one hour and the lock taken from
`LockStore::forUniqueJobs()`, so a same-day re-dispatch collapses into
a single queued pass. Retry behaviour is the shared `TunedQueueJob`
profile: three attempts, backing off 60 / 300 / 900 seconds.

## The two-key orphan test

A row is an orphan only when **both** keys fail:

1. **No recent activity.** No `transactions` row for this user has
   pointed at the counterparty within the last 365 days.
2. **No alias anchor.** No `merchant_aliases` row for this user has
   `friendly_name` equal to the counterparty's `merchant_name`.

A merchant the user has named survives a quiet year. A counterparty
with recent activity survives an alias rename. Only a row that is both
untouched and unnamed goes.

The 365-day window is measured on `transactions.created_at` — the row's
insert time — not on `posted_at`. That is deliberate: re-importing an
old statement re-arms retention for its counterparties, because the
evidence of interest is that the user is working with this data now,
not when the money moved. A 2019 statement imported today keeps its
payees for another year.

Both the number and the clock come from
`Modules\Core\Public\Support\RetentionWindow`, which
`PruneNotificationsJob` reads too. This job used to ask SQLite for its own
cutoff — `datetime('now', '-365 days')` — which is UTC, while
`transactions.created_at` is written from the app clock at `APP_TIMEZONE`.
The two sides of the comparison sat in different frames and the edge landed
an offset away from the rule written above: a row inserted at 15:00 was kept
where this page says prune. Nothing looked wrong, because rows still expired
roughly a year later.

`merchant_name` is only ever set by the merchant branch of the
resolution chain and by the triage accept path, which is why accepting
a [triage suggestion](triage-suggestions.md) matters here: it writes
`merchant_name`, and that is the column the alias anchor joins against.

## The prune

The prune is announced, not silent: one `TransactionMutated` (`edit`,
`counterparty_id => null`) per row it unlinks, then one `EntityMutated`
(`delete`) per counterparty it drops, in that order — a peer that saw
the delete first would replay a transaction pointing at a row it had
just dropped. `CounterpartyResolverService` emits on create and edit,
so without these a GC run on one device left the peer holding rows this
device deleted and links this device broke.

Both steps run inside one `$connection->transaction()`:

```
UPDATE transactions SET counterparty_id = NULL WHERE counterparty_id IN (orphans)
DELETE FROM counterparties WHERE id IN (orphans)
```

The NULLing is not a formality. `transactions.counterparty_id` carries
no `ON DELETE` cascade by design (see the
`add_counterparty_id_to_transactions` migration) — pruning a stale
payee must never take a historical transaction with it. The link goes;
the row stays.

Every clause in the job — the candidate select, the correlated
`NOT EXISTS` subqueries, the update, and the delete — carries its own
explicit `user_id` predicate. The job runs on a queue worker where the
`BelongsToUser` global scope does not fire, so those filters are the
only scope there is.

**The `EntityMutated` announcement carries a second consumer.** Because
the delete goes through the query builder, no Eloquent model event
fires, and `Categorization`'s `DeactivateRulesOnReferentDelete` — which
exists precisely so no active rule points at a dangling referent — was
listening only for that model event. A pruned merchant therefore left a
categorisation rule active on an id nothing resolves. That listener now
also reads this job's `EntityMutated` (`counterparties`, `delete`) and
switches the owning rule off; see
[categorization architecture](../categorization/architecture.md#app-level-referential-integrity).

## The encryption problem

`counterparties.merchant_name` is a `SensitiveFieldRegistry` column. In
plaintext installs the alias anchor is a single SQL `whereColumn`
equality against `merchant_aliases.friendly_name`, which is always
plaintext. Once `merchant_name` is encrypted that comparison silently
stops working — AEAD ciphertext never byte-equals plaintext, so every
alias-protected row would look unanchored and be pruned.

Worse, the job's only real dispatch origin is the daily scheduler tick:
a queue worker with no live session, and therefore no app-lock key with
which to decrypt anything.

`collectOrphans()` splits the predicate in two and branches only the
half that needs it.

The `merchant_name IS NULL` half is always safe.
`SensitiveColumnCodec::encryptAttrs()` only encrypts string values, so
a NULL is never turned into ciphertext and the half prunes
unconditionally in every branch.

The `merchant_name IS NOT NULL` half takes one of three routes:

| Encryption state | Route |
|---|---|
| Not encrypted | `collectPlaintextNamedOrphans()` — the original SQL equality, unchanged. |
| Encrypted, KEK available | `collectDecryptedNamedOrphans()` — load the user's alias names, load the candidates, decrypt each `merchant_name` in PHP, compare. |
| Encrypted, no KEK | Skip the half entirely and log a warning naming the user and the candidate count. |

The decrypt route exists for symmetry with a future request-bound
dispatch origin — it is never taken by today's daemon-only schedule —
and mirrors the decrypt-before-compare template
`FingerprintStage::detectConflicts()` already uses.

Two failure modes are handled explicitly, and both resolve towards
keeping data:

- **A candidate whose decrypt fails is skipped, not compared.**
  `SensitiveColumnCodec::decryptValue()` never throws; on failure it
  returns the raw ciphertext with `decrypted: false`. That value would
  match no alias, so comparing it would prune exactly the
  alias-protected rows the check exists to save.
- **No KEK skips the half rather than guessing.** Those candidates are
  simply re-evaluated on a later run that has a key. A row that should
  have been pruned surviving an extra day costs nothing; a wrongful
  prune is unrecoverable.

`resolveEncryptionContext()` has one more guard: the job's `handle()`
declares `Session`, `AppLockKeyService`, `EncryptionMigrationService`,
`SensitiveColumnCodec`, and `LoggerInterface` as nullable parameters, so
a legacy one-argument call leaves all of them null. That shape defaults
to "not encrypted" rather than gating on a key it was never handed.

## Related

- [Module architecture](architecture.md) — the module surface map.
- [Resolution chain](resolution-chain.md) — how the rows being pruned
  were created, and what `merchant_name` means.
- [Triage suggestions](triage-suggestions.md) — accepting a suggestion
  writes the `merchant_name` this job's alias anchor depends on.
