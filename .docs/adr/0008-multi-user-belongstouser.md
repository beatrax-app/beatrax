# ADR 0008 — Multi-user readiness via BelongsToUser + explicit user_id filters

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

beatrax shipped v1.0 as a single-user application. The user intended,
from the outset, to share the dashboard with a partner once the product
was proven. That commitment shaped the schema from Phase 1: every
domain table that holds user-scoped data carries a `user_id` foreign
key, and Eloquent models use a shared `BelongsToUser` trait that
auto-scopes queries.

The temptation to defer multi-user readiness was real. A single-user
app could have skipped the `user_id` column, the trait, the
authorization checks, and shipped faster. The cost would have shown up
the moment partner-sharing landed: a multi-month migration project to
backfill user IDs across every transaction, every rule, every cached
projection — and a high-stakes cutover where any missed query would
silently leak one user's data to the other.

By treating multi-user as a schema-level invariant from day one — even
while v1.0 ran with only the developer's single user account — the
v2.0 partner-sharing activation became an authentication-and-UI change
rather than a schema migration.

## Decision

Every user-scoped Eloquent model uses the `Modules\Core\Public\Concerns\BelongsToUser`
trait. The trait:

- Registers a global scope that applies `where('user_id', $userId)` to
  every query the model issues, where `$userId` is the
  currently-authenticated user resolved through a constructor-injected
  `AuthContext` collaborator.
- Asserts a non-null `user_id` on save; an insert without a `user_id`
  throws before hitting the database.
- Provides a `forUser(User $user)` query scope for the few cases that
  need to query across the current user — for instance, the
  chain-resolution job that operates per-user and needs to fetch a
  specific user's transactions.

Raw query-builder queries against user-scoped tables — the kind that
bypass Eloquent — must include an explicit `->where('user_id', $userId)`
filter. The [`noMerchantAliasesQueryWithoutUserIdFilter`](#) arch
invariant enforces this for the `merchant_aliases` table specifically;
the pattern generalises to every user-scoped table where raw queries
exist.

Every user-scoped route ships a cross-user 404 test: with users A and
B both holding a record, user A's request for user B's record returns
404, not 403, not 200, not "the wrong record". The 404 (rather than
403) is deliberate: it does not reveal that the record exists.

## Consequences

- **Cross-user leakage is structurally hard.** A developer writing a
  new query against a user-scoped table without the trait has to
  consciously fight the trait to get the global scope to drop. The
  default is correct; the unsafe path requires explicit opt-out.
- **The `users` table itself is exempt.** Admin/owner-managed flows in
  v2.0 (recovery-code reset, owner-resets-partner) read across the
  table; the `Auth` module's recovery surface uses an explicit owner
  check rather than the trait.
- **Eloquent relationships need explicit scoping.** `$user->transactions`
  works through the relationship FK; a cross-user join in a service
  must filter explicitly. Reviewers look for this pattern at PR time;
  arch tests catch the cases that have a known shape.
- **v2.0 activation cost was bounded.** Adding the second user
  required: a login UI, a profile switcher, an owner-managed user
  creation flow, and one shared SQLite file. No schema changes. No
  backfill. No cutover risk.
- **Backups capture all users together.** A `db:backup` from a shared
  store captures both users' data in one file; a partner who wants
  their own copy uses the per-user export endpoint described in
  [`legal/data-retention.md`](../legal/data-retention.md).

## Alternatives considered

- **Single-user v1, schema-migrate to multi-user later.** Rejected.
  The migration cost grows with every row in the database; for a
  long-lived ledger that retains all history forever, the cost
  compounds.
- **Per-user SQLite files.** Rejected. Joining across users (the
  partner-sharing case where "we" share a category list) would have
  required a manual cross-file join layer; sharing a single store
  via `user_id` filtering is simpler.
- **A "current user" query macro instead of a trait.** Rejected: the
  trait carries both the scope and the on-save assertion; the macro
  would have covered only reads.

## Related

- [ADR 0010 — Recovery codes, no SMTP reset](0010-recovery-codes-no-smtp.md)
  — the v2.0 auth posture this multi-user model lives inside.
- [Architecture — Data model](../architecture/data-model.md) — names
  every user-scoped table and the FK shape.
- [Architecture — Module boundaries](../architecture/module-boundaries.md)
  — describes the `BelongsToUser` trait's home and the arch invariants
  that enforce explicit `user_id` filters on raw queries.
