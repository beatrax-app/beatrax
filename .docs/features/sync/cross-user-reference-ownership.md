# Keeping two household members' rows apart

Two people in one household each run their own data through the same app and the same sync
fabric. Nothing about an op-log entry announces which of them it belongs to in a way that can
be trusted, and the ids inside it are ambiguous by construction. This page is about the two
separate things that have to be checked: *whose row is being written*, and *whose row is being
named*.

## The wire `user_id` proves nothing

An `OpLogEntry` carries a `userId`. It is tempting to treat it as the answer, and the code once
did. It is not: that field is the **sending device's local autoincrement**, and two devices
that were set up independently disagree about it for the same person. Comparing it rejected
every op a paired peer sent.

Membership is proven by the **device** instead. `$deviceKeys` comes from
`DeviceRegistryService::deviceKeys($userId)`, which is confirmed-only and user-scoped, so
another user's device simply has no key present and its entry cannot clear the Ed25519 gate.
Once an entry is past that gate its `userId` is **overwritten** with the replay scope rather
than compared against it.

The one exception is system cascade ops. Those are generated locally, bypass the signature
gate, and therefore keep the `user_id` comparison — for them it is a real check, not a
formality.

Every database write in a replay is additionally bound by `WHERE user_id = $userId`. An entry
whose `pk` names another user's row therefore matches nothing and updates nothing. That is
defence in depth, not the primary boundary.

## Ids collide across members, and that is the whole problem

Sync carries a row's local autoincrement id as its cross-device identity. A fresh phone has
only ever held one person's rows, so its accounts ran 1, 2, 3 and the next it minted was 4 —
while on the desktop, id 4 already belonged to the other member of the household.

Adding a cash entry on the phone therefore sent the desktop a transaction pointing at somebody
else's account. It was written, it was linked, nothing was quarantined, and the only visible
trace was the wrong name on a row.

So scoping the *row* is not enough. The **value** of a reference field has to be checked too.

## Guarding what a row names

Both write paths need the same gate, and each got it at a different time:

- **`CreateRow`.** `admissiblePayload()` has always gated references — but against a
  hand-written list of reference columns, and `pots` was absent from it entirely. A pot's
  `account_id` is `NOT NULL` and is joined for display next to the account's name and IBAN, so
  a pot created against another member's account was written and then shown under their bank
  details. The list is now derived from the live foreign keys rather than maintained by hand.
- **`Set`.** Nothing gated it at all. The entry is validly signed, it carries the author's own
  `userId`, the row it updates is genuinely theirs, and `scopeToUser()` bounds the `UPDATE`
  correctly. Every existing check passes. What was unguarded is the id in the value.

Two details make the gate harder than it looks:

1. **The merge registry is not an allow-list.** `resolveStrategy()` falls back to a default
   strategy for a field the registry does not name — `pots.account_id` is one — rather than
   refusing it. A guard that assumed "registered field" meant "known field" would miss exactly
   the columns nobody remembered to register.
2. **`migration_source_map.beatrax_id` has no foreign key and cannot have one.** The table it
   points at is whatever `beatrax_entity_type` says. Derivation from foreign keys is blind to
   it, and a `Set` carries only the id, so the entity type has to be read back off the existing
   row before the reference can be resolved at all.

## Absence is an ordering problem, not theft

A reference naming an id that *nobody* holds looks superficially like the cross-user case, and
conflating them would be a real bug in the other direction: reading the quarantine back later,
you would see a household member blamed for what was actually a race.

Ops arrive out of order. The referenced row may simply not have replayed yet. So an absent
reference is not quarantined as `cross_user`. The row is still refused — the database's own
foreign key does that — and it lands under a different reason, which is what makes the
quarantine log honest.

## See also

- [`oplog-replay-under-live-triggers.md`](oplog-replay-under-live-triggers.md) — the other
  half of what can refuse a replayed write.
- [`architecture.md`](architecture.md) — where the merge registry and quarantine sit.
