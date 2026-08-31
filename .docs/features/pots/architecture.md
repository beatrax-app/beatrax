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

Which is why the page subtitle no longer says the pots *always* add up to the
real balance. It sat directly above "Pots exceed real balance by EUR1.130,35",
and of the two the banner is the true one: over-allocation is a state this
module surfaces on purpose. The subtitle now describes the shape ("carved out
of a real account balance") and makes no claim the banner can contradict.

The three figures are the account's **own currency only**, so every other line
the account holds is left out of all three. `ReconciliationRow` names those
codes in `unconverted` and the header renders `core::money.not_converted`
beside them, the same way every other money surface in the app does — the
figures used to just be smaller, with nothing saying why.

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

`save()` also re-asserts that the account **holds a spendable balance**
(`AccountKind::holdsSpendableBalance()`), throwing
`AccountCannotHoldPotsException`. The picker has only ever offered those
accounts, but `accountId` arrives from the client: a pot on a credit card is
over-allocated by construction — the balance there is what is *owed* — so the
page printed "Pots exceed real balance by EUR704,00" beside "Allocated: EUR0,00"
and the pot could never be funded. The page's empty state is gated on the pots,
not on the accounts, so a pot already sitting on such an account still lists and
still archives; and a group whose account can no longer take one is not drawn
with an "Add pot" button that the writer would refuse.

Every one of these dispatches its capture events **after** its transaction
commits, never from inside the closure — `EnvelopeActivationService` relies on
exactly that when it archives the category-linked pots one at a time instead of
wrapping the walk in a spanning transaction, and a listener that reads the row
back from inside an open transaction sees a state no other connection has.

## Linking: goal-only

`PotWriter::linkGoal($user, $potId, $goalId)` is the write for the goal link and
touches only `goal_id`. Relinking used to go through `update()`, which meant
re-reading the pot's name to send it back unchanged: under the per-field
last-write-wins merge that made the linking device win the name field and lose a
rename made on the other one, and a name that read back blank refused the link
with "Enter a name for this pot." Both the one-pot-per-goal rule and the refusal
to hang a goal on a category-linked pot (`PotLinkedToCategoryException`) live in
this method, not in the Goals page that calls it.

The rule runs in **both directions**. One-pot-per-goal is checked by
`assertGoalOwnedAndFree()`; one-goal-per-pot is checked in `linkGoal()` itself,
which throws `PotAlreadyLinkedException` when the pot already funds another
goal. Only the first direction was guarded, and the second is the one a goal
write travels: a create could hand itself a pot another goal already held, the
pot moved, and the goal that had been funded by it read 0% with no pot and no
sign why. `update()` deliberately still re-points a pot's goal — that is the
pots page's own edit, an explicit statement about *that pot* — while
`linkGoal()` is the goals page asking for a pot, and there the pot must be free.

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
- **Exceptions** — `PotNotFoundException` (and its narrower
  `TargetPotNotFoundException`), `InvalidPotAmountException`,
  `InsufficientUnallocatedException`, `SelfTransferException`,
  `CrossAccountTransferException`, `GoalAlreadyLinkedException`,
  `PotAlreadyLinkedException`, `PotLinkedToCategoryException`,
  `AccountCannotHoldPotsException`.

Each of those is a separate sentence on the page, and the ones a `transfer()`
can raise are separate for exactly that reason — see
[move refusals](move-refusals.md). The two causes `PotNotFoundException`
covers, "no such pot" and "not yours", stay one sentence on purpose.

`PotBalanceQuery` answers what an account holds; assembling a pot as the
reader sees it — account, goal, category spend for the open period, last
ten movements — is a separate job, and lives in
`Internal/Services/PotRowLoader`.

The coverage line's spend half is **converted**, not filtered. What the pot
holds is genuinely denominated in the pot's currency; "what I spent in this
category this period" is not, and scoping it to the pot's currency dropped a
card denominated elsewhere from a figure sitting beside a balance that counted
everything. `categorySpent()` buckets by `settled_currency`, converts each
bucket into the pot's currency, and hands `PotRow` the codes it could not
price, which the card renders through `core::money.not_converted` —
`categorySpentIsPartial()` / `categorySpentUnconvertedList()`. It also owns the one read of a pot's
`pot_movements` sum, which `balanceForPot()` delegates to, so the pot cards
and the guard `PotWriter` checks against cannot drift apart.

Those buckets come from Ledger's own
[`SpendByCategoryQuery`](../ledger/architecture.md#spendbycategoryquery--the-split-aware-spend-read-model),
read once for every category on the page rather than once per pot. The line
asks the same question the budgets grid asks one screen away, and asking it
with a bespoke `settled_amount_minor < 0` sum answered a different one: it
counted an internal transfer, never netted a refund, and missed a split
outright — `SaveTransactionSplit` leaves a split parent's own `category_id`
null, so a €80 receipt split €60 groceries / €20 household showed €0.00 on the
pot card beside €60.00 on the grid.

The window is the **owner's** budget month — `containingForUser()`, never
`current()`. `PotBalanceQuery::forUser()` is a Public read that takes the user
it is answering for, and resolving the period off whoever the guard carries
made the coverage line follow the browsing session's `period_start_day`
instead of the pot owner's.

The card shows the last ten movements and used to stop there with nothing
saying an eleventh existed, so a pot's history read as complete when it was
not. `PotRow::movementCount` carries the real total — one grouped statement for
the whole list, not a query per card — and `hasOlderMovements()` drives the
`history.truncated` line under the list. A movement whose `kind` this build has
no case for is named as such rather than folded into one of the four:
`pot_movements.kind` has no CHECK, so a peer on a newer version writes its own
spelling through the op log and `PotMovementKind::from()` used to take the
whole page down on the older device
([a peer may be on a newer version](../sync/a-peer-may-be-on-a-newer-version.md)).

`linkedPotBalancesForUser()` reports `hasMovements` beside each linked pot's
balance, which is not `balance !== 0`: a pot funded and then emptied has a
contribution history and a zero balance, and the goal card tells the two apart
— one is asked for a first contribution and the other is not.
