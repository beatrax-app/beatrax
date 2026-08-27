# `Recurring` — detection under encryption

Income detection groups transactions by counterparty IBAN, and
`transactions.counterparty_iban` is one of the columns the product
encrypts at rest. That single fact decides where the detection job is
allowed to run, how it is dispatched, and what shape the clustering key
in `recurring_series` has to take.

This page explains the failure that shaped the design, the two dispatch
origins and what each can do, and what would break if the job were moved
back onto the queue.

## The failure: clustering ciphertext detects nothing, silently

`transactions.counterparty_iban` is listed in
`SensitiveFieldRegistry::columns()`. For a user who has enabled
encryption, the value stored in that column is not the IBAN — it is
ciphertext produced with a **random nonce per row**. Encrypting the same
IBAN twice produces two different ciphertexts. That is the correct
property for a cipher to have; it is fatal for a grouping key.

`IncomeSeriesDetector` groups rows by `counterparty_iban + currency`. If
it reads the raw stored column for an encrypted user, twelve monthly
salary payments from one employer produce twelve *different* group keys
— twelve groups of one row each. Every one of them is below the
minimum-occurrences gate of 2, so every one is dropped.

The result is not an error. It is not an exception, a failed job, or an
empty-state message. Detection runs, reports success, and finds nothing.
An encrypted user would simply never see an income series and would have
no way to tell that the feature was broken rather than that their data
had no pattern in it.

## The fix: decrypt before the value becomes a key

`IncomeSeriesDetector::detectForUser()` takes an optional `Session`. When
one is supplied and the row's IBAN is non-empty, the value is passed
through `SensitiveColumnCodec::decryptValue()` **before** it participates
in group-key or cluster-key derivation. Same logical IBAN, same
plaintext, one group.

The codec call is a documented no-op pass-through for a value that is
not encrypted — a user who never enabled encryption, or rows written
before the migration. So passing a session is always safe; it is never
"decrypt or fail".

The `Session` parameter is deliberately **not** part of the
`SeriesDetector` contract. PHP lets an implementing method add extra
parameters that have defaults without breaking interface conformance, so
every caller that resolves detectors through the generic interface keeps
working unchanged. Only `DetectRecurringSeriesJob` knows which dispatch
origin the current run came from, and it is the only caller that decides
whether to hand a session over.

## The two dispatch origins

Decrypting needs the user's key-encryption key, and the KEK is only
reachable through a Session the user has unlocked in this process. There
are exactly two ways the detection job starts, and they differ on that
one point.

**In-request, key available.** The "Detect now" button on `/recurring`
(`RecurringPage::reDetect()`) and `BusRecurringDetectionDispatcher`
(called from `ConfirmImport` and from onboarding's first-import step)
all dispatch with `dispatchSync()`. The job runs in-process, on the same
request whose Session is already unlocked, so the KEK is present and
income detection can run in full.

**Scheduled, key absent.** The daily `recurring.detect` scheduler entry
in `routes/console.php` dispatches through the real queue. A queue
worker has never unlocked anybody's Session, so for an encrypted user
the KEK is not there and cannot be made to be there.

`dispatchSync()` was chosen over queueing precisely so the KEK stays in
process. The alternative — passing key material to a queued job — would
mean serialising it onto the `jobs` table, which is exactly what the
encryption design exists to prevent.

## What the job does when the key is not there

`DetectRecurringSeriesJob::handle()` probes two things:

- `AppLockKeyService::release($session)` — is a KEK reachable at all?
- `EncryptionMigrationService::isEnabled($userId)` — is this user
  encrypted in the first place?

Detection of IBAN-keyed series is permitted when the KEK is present
**or** the user is not encrypted. Otherwise `IncomeSeriesDetector` is
skipped: not called with degraded inputs, not called and ignored —
never invoked at all. A warning is logged naming the user, saying income
detection was skipped for this sweep and will run on the next in-app
"Detect now" refresh.

`ExpenseSeriesDetector` clusters on `counterparty_normalized`, which is
already a keyed blind index — it never decrypts anything, so it is called
on every sweep. What it cannot always do without a KEK is *name* the
series: `detected_name` is a displayed column and a keyed digest must not
reach it, so a cluster with no readable name is held back for a later
sweep rather than written. Two sources still answer without a KEK — the
user's own `merchants` row, and, for a user who is not encrypted at all,
the key itself — so a locked scheduled sweep keeps most expense series
current and defers the rest.

That deferral used to be silent: no row, no event, no log line, which
reads exactly like a user with no recurring expenses. Both detectors now
count the clusters they held back and log one warning per sweep naming
the count and the user.

The probe is conditional on all three collaborators being present.
Callers that invoke `handle()` without a session, an
`AppLockKeyService` and an `EncryptionMigrationService` — the older
four-argument test shape — default to "full capability", which is
correct for fixtures that are never encrypted.

## The cost that was accepted: no dispatch collapsing

`DetectRecurringSeriesJob` implements `ShouldBeUniqueUntilProcessing`
keyed on the user id, so two dispatches for the same user collapse into
one queued pass. That lock is enforced by `PendingDispatch::shouldDispatch()`,
and `dispatchSync()` never goes through `PendingDispatch` at all.

So on the in-request path the lock does nothing. An import-confirm
followed immediately by a "Detect now" click runs the whole sweep twice,
in sequence, on the request. Detection is idempotent — the cluster-key
UNIQUE constraint and the occurrence INSERT-OR-IGNORE both hold — so the
second pass is redundant work, not a wrong result. This was accepted as
the price of keeping the KEK in process. The uniqueness lock still does
its job on the scheduled queue path, where the collision risk is real.

## The derivative that used to be plaintext

`recurring_series.cluster_counterparty_key` holds a **keyed digest**, not
the decrypted IBAN. For a user with at-rest encryption enabled it is
`HMAC-SHA256` under that user's blind-index key, derived under the
`counterparty-iban` domain for an income row and `counterparty-normalized`
for an expense one. See [which columns are encrypted at
rest](../sync/sensitive-columns-at-rest.md#the-keyed-blind-index-in-counterparty_normalized).

The column is a lookup key: it is what the cadence-flip fallback matches
on when a series' `cluster_key` no longer matches because its cadence
band moved. Random-nonce ciphertext cannot serve as a `WHERE` value — two
encryptions of the same IBAN do not compare equal — which is why this is
a blind index rather than AEAD. Equality and uniqueness survive exactly;
the readable IBAN does not.

A "plaintext derivative" is the general shape: a value derived from
sensitive plaintext and persisted unencrypted because some mechanism
needs it to be comparable. The registry tracks these explicitly so they
are visible rather than discovered.

`cluster_key` moves with it. It is composed **over** the counterparty
key, so the two are rewritten in one statement or the series splits — a
row left with a keyed counterparty column beside
`income::nl91rde0987654321::eur::monthly` still printed the payer's IBAN,
in an indexed column that syncs in the clear. Healing lazily through
`SeriesRefresher` is not enough: both detectors return early for
`Rejected` and `Snoozed` series, and a series whose transactions fall
outside `recurring_detection_window_months` is never revisited.

The migration this page once said the hardening was deferred for is real,
and it is the reason for two decisions worth reading together.
`CounterpartyKey::normalizeIban()` trims and upper-cases but does **not**
strip interior whitespace: compacting would change what an already-keyed
spaced IBAN hashes to, orphaning exactly the rows it was meant to
protect. The enable-time sweep, by contrast, *does* normalise whitespace
before deciding whether a stored value is shaped like an IBAN, because a
statement that printed one spaced would otherwise be classified as a name
and keyed under the wrong domain. The residual is that the same payer's
IBAN arriving spaced in one statement and compact in another still
clusters as two series; closing it needs the normaliser to compact **and**
a one-shot re-derive of every row written since enable.

## What breaks if this is changed

If a future change replaces `dispatchSync()` with a normal queued
dispatch on the in-request paths, nothing fails loudly. The job still
runs, expense detection still works, and the KEK probe does exactly what
it is supposed to. But there is then **no** dispatch origin that has a
KEK, so income series would never be detected for any encrypted user,
forever — visible only as a repeated log warning. The regression tests
that pin this cover both origins: same-IBAN rows clustering into one
series on the in-request path, and the explicit skip-plus-warning on the
scheduled path.

If a future change instead removes the KEK probe and lets
`IncomeSeriesDetector` run without a session on the scheduled path, the
detector reads ciphertext, groups it, finds nothing, and reports
success — the original failure, back again.

## Related pages

- [How a series is detected](series-detection.md) — the clustering,
  tolerances and cadence bands this posture protects.
- [The detection fixture corpus](detection-corpus.md) — including the
  encrypted-clustering cases.
- [Sensitive columns at rest](../sync/sensitive-columns-at-rest.md) —
  the field registry, the codec and the at-rest encryption substrate.
