# Phase 16.1.1 — Deferred Items

Items discovered during plan execution that are OUT OF SCOPE for the plan that surfaced them.
Each entry records the discovery source so a later plan or maintenance pass can pick it up.

## Pre-existing test failures (out of scope for plan 16.1.1-02)

Discovered while running `vendor/bin/pest Modules/EmailScan/tests` as a wider smoke check
after Task 1 of plan 16.1.1-02. Reproduced against the plan's base commit
(`22fe7c1`) — the failures pre-date this plan and are unrelated to the
OAuth modal mount relocation.

| Test file | Status on base `22fe7c1` | Note |
| --------- | ------------------------ | ---- |
| `Modules/EmailScan/tests/Feature/BackfillProgressPollTest.php` | failing | Out of scope for 16.1.1-02 |
| `Modules/EmailScan/tests/Feature/InboxesEmptyStateTest.php` | failing | Out of scope for 16.1.1-02 |
| `Modules/EmailScan/tests/Feature/InboxesHealthBadgeRenderTest.php` (7 cases) | failing | Out of scope for 16.1.1-02 |
| `Modules/EmailScan/tests/Feature/TopNavBadgeViaComposerTest.php` | failing | Out of scope for 16.1.1-02 |

Counts: 12 failed cases across 4 files, plus 3 todos. The Onboarding module's own
26 feature tests are green, including the new `ConnectEmailStepDispatchesGlobalOAuthOpenTest`
this plan introduces.

**Recommendation:** address these in a follow-up debug pass (likely under a `/gsd-debug`
flow rooted in the EmailScan module) — they are NOT introduced by plan 16.1.1-02 and
NOT mentioned in the plan's `<files_modified>` or acceptance criteria.
