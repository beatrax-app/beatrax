# `Transfers` — code

The file-level map for the module.

## Directory layout

```
Modules/Transfers/
├── Public/
│   ├── Contracts/
│   │   └── PairsTransferLegs.php
│   └── Services/
│       └── PairLookup.php
├── Internal/
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
  - `PairsTransferLegs::pair(int $transactionId, User $user):
    void` — single sanctioned entry point.
- **Services/**
  - `PairLookup::partnerFor(int $transactionId, User $user):
    ?Transaction` — read-side query consumed by Chains.

## Internal services

- `Internal/Services/TransferPairer` — concrete
  `PairsTransferLegs`. Singleton-bound; deterministic;
  no per-instance state.
- `Internal/Listeners/PairTransferCandidates::handle($event)`
  — per-row listener auto-resolved via constructor DI.

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
