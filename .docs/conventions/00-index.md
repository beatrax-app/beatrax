# Conventions

Coding conventions that every backend contributor — human or AI — is expected to
follow. Unlike the `architecture/` tree (which describes the *shape* of the system) or
the `adr/` tree (which records *why* structural decisions were made), this subtree holds
the *day-to-day rules* for how code is written.

Each convention here is designed to be **portable**: a single self-contained file that
can be copied into another project's `.docs/conventions/` tree, paired with its Pest
enforcement test, and be immediately in force.

## Files

| File | What it covers |
|---|---|
| [Code comments](code-comments.md) | When a comment is allowed, what shape it takes, and how `.docs` carries the rest |

## Conventions

- **Two enforcement layers.** Each convention separates its *mechanical* rules (shape,
  enforceable by a Pest test) from its *judgment* rules (intent, enforceable only by a
  reviewer). The doc states both; the test enforces the mechanical subset.
- **Copyable.** A convention file plus its test are the unit of portability. Avoid
  project-specific references in the rule text itself; keep those in the ADR that records
  the decision.
