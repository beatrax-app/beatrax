# `Pots` — architecture

The `Pots` module lets a user carve virtual sub-balances ("envelopes") out of
a real account's balance — e.g. "Rent", "Emergency fund" — without moving any
real money. A pot has no stored balance column: its balance is always the
signed `SUM(amount_minor)` of its `pot_movements` rows, computed at read
time.

## Reconciliation: real, allocated, unallocated

For an account with active pots, three figures always relate as
`real = allocated + unallocated`:

- **real** — the account's actual current balance (from the Ledger module's
  `AccountBalanceQuery`).
- **allocated** — the sum of all *active* pots' balances on that account.
  Archived pots are excluded.
- **unallocated** — `real - allocated`, computed at read time and never
  stored.

`unallocated` can go negative when real-world spending pulls the account
balance below what pots have already claimed. This is surfaced (an
`isOverAllocated` flag drives warning styling in the view) rather than
silently corrected — the system never auto-rewrites a pot's balance to make
the books look tidy.

## Writes: fund, withdraw, transfer, archive, restore

Every pot mutation is a row insert into `pot_movements`, never a balance
column update:

- **fund** — a positive movement. Before inserting, `PotWriter` re-reads the
  account's unallocated balance *inside the same transaction* that performs
  the insert, so two concurrent fund actions cannot both pass the check
  against a stale unallocated figure and jointly over-allocate the account
  (a save-then-check-then-insert race).
- **withdraw** — a negative movement, checked against the pot's own current
  balance the same way.
- **transfer** — an atomic pair of movements (a `transfer_out` on the source
  pot, a `transfer_in` on the target), both pots required active, owned by
  the user, and sharing the same `account_id` — transfers are intra-account
  only. The source balance check runs inside the same transaction as both
  inserts.
- **archive** — releases any remaining balance back to unallocated via one
  final `withdraw` movement (memo: "Released on archive"), then flips
  `status` to `archived`, both in the same transaction. An archived pot
  always reads back as balance 0.
- **restore** — brings the pot back active with **no movements inserted** —
  it comes back empty, not re-funded. If another pot has since claimed the
  same linked goal while this one was archived (one-pot-per-goal), the
  restored pot loses its goal link rather than creating a second active pot
  on that goal.

Initial-amount pot creation (`save()` with an optional starting fund) runs
the row creation and the initial funding check in one transaction too, so an
over-limit initial amount rolls back the whole pot — never an orphan
zero-pot left in the list.

## Linking: goal-only

A pot may optionally link to exactly one savings goal (never a category —
category-linking existed in an earlier design and was retired by the
envelope-budgeting cutover; `PotWriter` now rejects any non-null
`categoryId` outright, failing loudly rather than silently accepting a link
shape the rest of the system no longer expects). One-pot-per-goal is
enforced on both create and update: linking a goal that already has another
active linked pot throws.

## Ownership and lifecycle safety

Every `PotWriter` mutation resolves the pot through an explicit
`user_id = $user->id` filter with the `BelongsToUser` global scope bypassed
— the same pattern `GoalWriter` uses, and for the same reason: the ambient
scope reads `CurrentUser` and is a no-op in an unauthenticated context, so
relying on it alone for ownership is a latent cross-user access path. A
missing or foreign pot id throws `PotNotFoundException` from the write
actions and silently no-ops from the lifecycle actions (`archive`/`restore`),
matching `GoalWriter`'s convention.

## Public surface

- **Services/PotBalanceQuery** — the read model: `forUser()` /
  `archivedForUser()` (pot cards), `reconciliationForAccount()` (the
  real/allocated/unallocated header), `balanceForPot()` /
  `currentUnallocatedForAccount()` (the figures `PotWriter` checks against),
  and the goal-linkage lookups (`linkedPotBalancesForUser()`,
  `linkedPotIdForGoal()`, `currencyForLinkedPot()`) the Goals module
  consumes to show a linked pot's balance as a goal's contribution.
- **Services/PotWriter** — the sole write path described above.
- **Dto** — `PotRow` (one pot card), `PotMovementRow` (one line of inline
  movement history), `ReconciliationRow` (one account's reconciliation
  header).
- **Exceptions** — `PotNotFoundException`, `InsufficientUnallocatedException`.
