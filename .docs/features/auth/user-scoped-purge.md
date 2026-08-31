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
