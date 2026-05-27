# Architecture Decision Records

Each ADR follows the **Status / Context / Decision / Consequences** shape.

- **Status** — Accepted, Superseded by ADR-NNNN, or Deprecated.
- **Context** — What forced the decision; what the alternatives were.
- **Decision** — What we chose, in one or two sentences.
- **Consequences** — What this commits us to, what it rules out, and what tests or
  invariants enforce it.

ADRs are append-only. When a decision changes, a new ADR supersedes the old one and the
old ADR's Status is updated with a pointer to the replacement.

## Index

The ADR set is populated in a follow-up pass. Until then, decision context lives in the
[Architecture](../architecture/) and [Features](../features/) subtrees alongside the
mechanisms each decision shaped.
