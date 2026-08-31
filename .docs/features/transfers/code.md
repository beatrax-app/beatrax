# `Transfers` — code

The file-level map for the module.

## Directory layout

```
Modules/Transfers/
├── Public/
│   ├── Contracts/
│   │   └── PairsTransferLegs.php
│   ├── Enums/
│   │   └── CounterLegOrder.php
│   ├── Services/
│   │   ├── PairLookup.php
│   │   └── PairUnlinker.php
│   └── Support/
│       ├── CounterLegMatch.php
│       └── CounterLegWindow.php
├── Internal/
│   ├── Exceptions/
│   │   └── MismatchedTransferUserException.php
│   ├── Services/
│   │   └── TransferPairer.php
│   └── Listeners/
│       └── PairTransferCandidates.php
├── Providers/
│   └── TransfersServiceProvider.php
└── tests/
    ├── Unit/
    └── Feature/
```

No `Models/`, `Database/Migrations/`, `Routes/`, or
`Resources/views/` — the module is purely a matcher + listener.

## Public API

- **Contracts/**
  - `PairsTransferLegs::pairOne(Transaction $tx, User $user):
    ?int` — single sanctioned write entry point; returns the
    partner id when a pair was formed.
  - `PairsTransferLegs::pairOrphansForUser(User $user): int` —
    bulk sweep; returns the number of NEW pair links written.
- **Services/**
  - `PairLookup::isPaired(int $txId, User $user): bool` and
    `PairLookup::partnerId(int $txId, User $user): ?int` — read
    the persisted `pair_transaction_id` for a row.
  - `PairLookup::counterLegOnAccount(CounterLegMatch $match,
    CounterLegWindow $window, User $user): ?int` — the single
    counter-leg search. Every predicate is a required constructor
    argument of the two value objects, so neither caller inherits
    a bound it did not ask for. Consumed by
    [`Chains`](../chains/code.md)'s `PaypalFundingResolver`
    deterministic arm and by this module's own `TransferPairer`
    forward arm.
  - `PairUnlinker::unpair(int $userId, int $survivorId,
    TransactionType $deletedType): ?TransactionType` — the
    implementation of [`Ledger`](../ledger/code.md)'s
    `UnpairsTransferLegs`. Returns the survivor's new type, or
    null when nothing was retyped.
- **Support/**
  - `CounterLegMatch` / `CounterLegWindow` — the two value
    objects `counterLegOnAccount` takes. Neither carries a
    default; the window owns the ordering because
    `NearestToCentre` measures from the date the window centres
    on.
- **Enums/**
  - `CounterLegOrder` — `NearestToCentre` (chain resolution) /
    `EarliestBooked` (the pairer). Both run out through
    `booked_at` then `id`, so the ordering is total either way.

## Internal services

- `Internal/Services/TransferPairer` — concrete
  `PairsTransferLegs`. Singleton-bound; deterministic;
  no per-instance state. Its forward arm holds no query of its
  own: it resolves the partner account, then asks
  `PairLookup::counterLegOnAccount`.
- `Internal/Listeners/PairTransferCandidates::handle($event)`
  — per-row listener auto-resolved via constructor DI. Raises
  `Internal/Exceptions/MismatchedTransferUserException` when the
  event's `user` and the transaction's `user_id` disagree.

## Models + migrations

The module owns no Eloquent models and no migrations. The
`transactions.pair_transaction_id` column is owned by
[`Ledger`](../ledger/code.md) (migration
`2026_05_15_010002_add_pair_transaction_id_to_transactions.php`).

## Provider wiring

`TransfersServiceProvider::register()`:

- Singletons `PairLookup`.
- Singletons `TransferPairer`.
- Binds `PairsTransferLegs` → `TransferPairer`.
- The listener stays auto-resolved via constructor DI when the
  dispatcher invokes it (no explicit binding needed).

`TransfersServiceProvider::boot()`:

- Subscribes `PairTransferCandidates` to
  `Import::TransactionImported`.
- Does NOT call `loadMigrationsFrom()` /
  `loadRoutesFrom()` / `loadViewsFrom()` — there's nothing
  to load.
