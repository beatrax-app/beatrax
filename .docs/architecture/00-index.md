# Architecture

Architecture topics describe how the system fits together at the module, data, and
pipeline level — the cross-cutting shape that no single feature owns.

A topic in this subtree answers "how does X work across the codebase?" rather than
"how does Module Y behave?". Module-internal deep dives live under
[Features](../features/) instead.

## Topics

| Topic | What it covers |
| --- | --- |
| [Module boundaries](module-boundaries.md) | The thirty-four bounded modules, the `Public/Internal/Models/` split, and the twenty-nine arch invariants that hold the lines |
| [Ingestion pipeline](ingestion-pipeline.md) | The end-to-end flow from raw source file (CSV / CAMT / MT940 / PDF / `.eml`) to canonical `Transaction` row, including the idempotency contract |
| [Chain resolution](chain-resolution.md) | PayPal funding chains, ICS bulk-iDEAL settlement chains, the `pair_transaction_id` linkage, and the known-counterparty-IBAN alias bridge |
| [Categorization](categorization.md) | The two-layer rule-and-memory categorizer, the ≥40% confidence gate, and the receipt-vs-statement enrichment conflict resolver |
| [Data model](https://github.com/beatrax-app/spec/blob/main/20-architecture/data-model.md) | Table-by-table layout grouped by owning module, the trust-boundary columns, and the state-machine sole-mutator rule |

## How these fit together

The pipeline shapes the data: every row in `transactions` enters the table through
the [ingestion pipeline](ingestion-pipeline.md), is touched once at categorisation
time by the [categorizer](categorization.md), and may later be paired across
accounts by [chain resolution](chain-resolution.md). The
[data model](https://github.com/beatrax-app/spec/blob/main/20-architecture/data-model.md) is the static map; the other three topics are the
dynamic flows that operate over it. The [module boundaries](module-boundaries.md)
topic is the structural rule everything else respects.

For the historical decisions that shaped these topics — why modules at all, why
SQLite, why local-only, why brick/money — see the
[decision records](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/).
