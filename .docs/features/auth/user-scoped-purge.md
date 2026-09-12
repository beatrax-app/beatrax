# The user-scoped purge

One sweep removes everything a single account owns on this device. Two callers
reach it: `DeleteAccountAction`, which checks a password first because a person
is leaving, and `PurgeUserDataAction`, which does not because its caller has no
credential to check — today that is `demo:seed --reset`.

## Discovery, not a list

`UserScopedDataPurge` asks the live schema which tables carry a `user_id`
(`getTableListing()` + `getColumnListing()`) and sweeps those, retrying until a
pass clears nothing so foreign-key ordering resolves itself. It then reads back
what it deleted; a table the sweep missed throws `AccountPurgeException` instead
of surviving.

A written list goes stale the first time a module adds a table, and it fails as
silent orphaned financial data rather than as a broken build. Of the 82 tables
carrying a `user_id`, 67 hold a foreign key to `users` that cascades, so a
written list looks complete while the delete does the work. The other 15 hold
nothing:

```
device_registry           hlc_clock_state           ledger_backfill_state
mobile_import_intent      mobile_sync_progress      notification_preferences
op_log_entries            op_log_quarantine         pairing_tokens
sessions                  sync_backfill_state       sync_encryption_state
sync_peer_catch_up_state  sync_sessions             transaction_search_docs
```

The demo reset kept its own list and named one of them. A desktop reseeded
repeatedly carried 9,765 rows keyed to users that no longer existed, 8,872 of
them op-log entries, in an app whose whole store is one SQLite file.

## Four things the schema sweep cannot see

Each is swept explicitly, and each is a table where ownership is not a column:

| Table | How the owner is written |
|---|---|
| `jobs`, `failed_jobs` | serialised inside `payload`, in a bare and an escaped spelling |
| `cache` | the user id is the key suffix, anchored so `:1` cannot match `:11` |
| `relay_mailbox` | addressed by device id — read from `device_registry` *before* the sweep takes those rows |
| `rule_actions`, `rule_conditions` | `rule_id` only; swept as orphans rather than trusting the cascade |

## What survives on purpose

- **`op_log_entries.origin_user_id`.** Provenance on *another* account's
  replicated entry. Ownership is `user_id` and nothing else; deleting by the
  origin column would erase a household member's history from their own log.
- **Rows whose `user_id` is NULL.** The default category tree and guest
  sessions are shared, not owned, and the sweep matches on the id.
- **Every other account.** A paired household device keeps its own replica —
  the settings copy says so out loud — and the purge is one id at a time.
- **Non-demo users, on the reset path.** `demo:seed --reset` resolves ids from
  the demo usernames and purges those; `import_runs` not stamped
  `source_format = 'demo'` are left alone as well.
- **Files and the keychain, on the reset path.** `DeleteAccountAction` adds
  `UserScopedFilePurge` and `ColdStartVault::forget()` around the row purge.
  The reset does neither, because the demo seeders write neither.

## The two file tiers, and why neither is inside the transaction

`UserScopedFilePurge` splits the account's files by what a paired peer could put
the account back through.

`keyedToTheAccount()` is the sync identity, the group keyring and the
open-banking connector secret — three unlinks without which the deletion is not
finished. Each path is read back after the removal, because
`Illuminate\Filesystem\Filesystem::delete()` reports a refused unlink by
returning `false` and never by throwing: the return value was ignored, so the
ordinary failure was not merely swallowed, it was never noticed. A survivor
comes back **by name**.

`residue()` is the downloaded mail and, for the last account on the device, the
device-wide trees. Those are bulk deletes whose survival is disclosure rather
than a way back in, so each path is independent of the others and a survivor is
logged by name rather than as a count.

Both tiers run **after** the commit. The keyed tier used to run inside the
deletion transaction, beside `ColdStartVault::forget()` and described as
irreversible on the same terms, so that a refused unlink threw and the screen's
"Nothing was changed" was true. It bought one failure at the cost of a worse
one: the unlinks happen before the throw, so **every rollback past that point
restored the account's rows with its keys already destroyed** — an account the
reader did not finish deleting, surviving as sealed columns nobody can read,
with no tombstone and nothing raised. Three things could take that rollback: the
tier's own throw on a refused unlink, a failure of the commit itself, and the
one row count taken after it.

The keychain clear stays inside, and no longer claims to be on the same terms:
`forget()` writes the enrolment flag back through the lock gateway, so running
it after the row purge would resurrect a deleted row. A rollback cannot put the
operating system's entry back either — but a reader left on PIN-only unlock is
not a reader left with an unreadable ledger, which is why one of the two moved
and the other did not. Both are reported past the commit, in one place, as what
they are: key material that outlived the account.

## The debt a committed deletion leaves

Moving the unlink past the commit opens the opposite window — rows gone, key
material still on the disk — and that window is what `account_key_purge_state`
closes. It is the shape `sync_backfill_state`, `ledger_backfill_state` and
`anomaly_backfill_state` already use, for the reason written up as
[a claim is not a completion](../../conventions/a-check-another-writer-can-invalidate.md#a-claim-is-not-a-completion):
one fact is true before the work and another after it, and a single column asked
to carry both answers the first question by lying about the second.

- **The claim** is written inside the deletion transaction, so it commits
  exactly when the rows go and rolls back exactly when they stay. A rollback
  therefore leaves no debt, because there is no deletion to finish.
- **The completion** is stamped only once all three paths are confirmed gone.

`auth:sweep-owed-key-material` runs hourly and settles whatever is still
outstanding. It is not a retry of something that cannot work: the refusal this
tier meets in practice is a held handle — the shape a file lock takes on
Windows — and the next hour is usually past it. The command is in
`MobileBackgroundSchedule::requiredOnDevice()` because the files are on *this*
device's disk and no peer can reach them, and a phone can be the only device a
household owns.

The column is `account_id` and not `user_id`, which is load-bearing rather than
stylistic: `UserScopedDataPurge` discovers the tables it sweeps by that column
name, so the other spelling would delete, inside the very transaction that
writes it, the row recording what that transaction still owes. The sweep also
skips any id the schema has since handed out again — a live account's key
material is its own, and unlinking it would be this defect pointed the other
way.

What the reader is told changes with the ordering. A refused unlink no longer
reports "Your account was not deleted", because the rows are gone and that
sentence would be false; the deletion is reported as the deletion it is, and the
surviving paths are logged by name for the sweep to clear.
