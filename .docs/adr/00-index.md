# Architecture Decision Records

Each ADR follows the **Status / Context / Decision / Consequences** shape.

- **Status** — Accepted, Superseded by ADR-NNNN, or Deprecated.
- **Context** — What forced the decision; what the alternatives were.
- **Decision** — What we chose, in one or two sentences.
- **Consequences** — What this commits us to, what it rules out, and what tests or
  invariants enforce it.

ADRs are append-only. When a decision changes, a new ADR supersedes the old one and the
old ADR's Status is updated with a pointer to the replacement. A graduated ADR may
legitimately cite the phase number it came from (`Phase 17`, decision `D-32`) as
historical provenance.

## Index

| #    | Title                                                                | Status   |
| ---- | -------------------------------------------------------------------- | -------- |
| [0001](0001-modular-architecture.md)        | Modular architecture via `nwidart/laravel-modules`           | Accepted |
| [0002](0002-di-only-rule.md)                | Dependency injection only; no facades or global helpers     | Accepted |
| [0003](0003-hippocratic-3-0-license.md)     | Hippocratic License 3.0                                      | Accepted |
| [0004](0004-local-only-hosting.md)          | Local-only hosting; no cloud, telemetry, or remote logging   | Accepted |
| [0005](0005-sqlite-wal.md)                  | SQLite with WAL journal mode as the canonical store          | Accepted |
| [0006](0006-nativephp-desktop-shell.md)     | NativePHP as the desktop shell                               | Accepted |
| [0007](0007-database-queue-driver.md)       | Database queue driver in the shipped bundle; Horizon is dev-only | Accepted |
| [0008](0008-multi-user-belongstouser.md)    | Multi-user readiness via `BelongsToUser` + explicit `user_id` filters | Accepted |
| [0009](0009-brick-money-multi-currency.md)  | `brick/money` for multi-currency arithmetic                 | Accepted |
| [0010](0010-recovery-codes-no-smtp.md)      | Password reset via recovery codes; no SMTP-based reset in v2.0 | Accepted |
| [0011](0011-code-comment-policy.md)         | Code comment policy: readable code, architecture in `.docs`   | Accepted |

## Reading order

For a first pass, start with the two structural decisions that shape every other
module's code:

1. [ADR 0001 — Modular architecture](0001-modular-architecture.md)
2. [ADR 0002 — DI-only rule](0002-di-only-rule.md)

Then the privacy posture and how it shows up:

3. [ADR 0004 — Local-only hosting](0004-local-only-hosting.md)
4. [ADR 0003 — Hippocratic 3.0 license](0003-hippocratic-3-0-license.md)

Then the operational layer:

5. [ADR 0005 — SQLite with WAL](0005-sqlite-wal.md)
6. [ADR 0007 — Database queue driver](0007-database-queue-driver.md)
7. [ADR 0006 — NativePHP desktop shell](0006-nativephp-desktop-shell.md)

Then the domain-specific calls:

8. [ADR 0009 — `brick/money`](0009-brick-money-multi-currency.md)
9. [ADR 0008 — Multi-user via `BelongsToUser`](0008-multi-user-belongstouser.md)
10. [ADR 0010 — Recovery codes, no SMTP reset](0010-recovery-codes-no-smtp.md)

And the coding-convention invariant that shapes every source file:

11. [ADR 0011 — Code comment policy](0011-code-comment-policy.md)
