# Features

Per-module deep dives following a shared `_template/` shape. Each module that earns its
own write-up gets a single file describing what it does, the public contract it exposes,
the events it raises or listens to, and the operational notes a reader needs to extend
or debug it safely.

## Index

The feature write-ups land in a follow-up pass. Expected coverage:

- One file per bounded module: `auth`, `core`, `desktop`, `dev-mode`, `import`, `ledger`,
  `categorization`, `chains`, `recurring`, `drift-alerts`, `forecasting`, `email-scan`,
  `receipts`, `transfers`, `counterparties`.
- A `_template/` directory holding the canonical shape for new module write-ups.
