---
phase: 17-ci-cd-pipeline-code-signing
plan: 04a
type: execute
wave: 1
depends_on: []
files_modified:
  - Modules/Core/Database/Migrations/XXXX_create_user_preferences_table.php
  - Modules/Core/Models/UserPreference.php
  - Modules/Core/tests/Feature/UserPreferencesTableTest.php
autonomous: true
requirements:
  - gap-user-preferences-foundation
requirements_addressed:
  - gap-user-preferences-foundation
must_haves:
  truths:
    - "user_preferences table exists with (user_id, created_at, updated_at) and a unique constraint on user_id"
    - "user_preferences is one row per user (per-user single-row preferences store)"
    - "Subsequent plans add columns via additive migrations (skipped_update_versions JSON in 17-04; counterparty_index_view string in 17-06b)"
    - "UserPreference Eloquent model uses BelongsToUser and lives in Modules/Core/ (cross-module surface)"
    - "Cross-user isolation enforced via BelongsToUser + explicit ->where('user_id', $userId) in tests"
  artifacts:
    - path: "Modules/Core/Database/Migrations/XXXX_create_user_preferences_table.php"
      provides: "user_preferences shared schema; per-user single row"
    - path: "Modules/Core/Models/UserPreference.php"
      provides: "Eloquent model with BelongsToUser, fillable, immutable timestamps"
  key_links:
    - from: "Plan 17-04 (auto-update)"
      to: "user_preferences table"
      via: "Additive migration adds skipped_update_versions JSON column"
    - from: "Plan 17-06b (counterparty UI)"
      to: "user_preferences table"
      via: "Additive migration adds counterparty_index_view varchar column"
---

<objective>
Create the shared `user_preferences` table that subsequent plans extend with their own columns via additive migrations.

Purpose: Both Plan 17-04 (skip-update-version persistence) and Plan 17-06b (counterparty_index_view persistence) need a per-user single-row preferences store. Without a shared foundation, the two plans would either duplicate the create-table migration (causing migration order conflicts) or one would silently depend on the other's migration order. This Wave-1 foundation plan owns the create-table migration; downstream plans own additive column-add migrations.

Output: A green `user_preferences` table in Modules/Core/ with a BelongsToUser-scoped Eloquent model. NO feature columns yet — those land in 17-04 and 17-06b as additive migrations.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/phases/17-ci-cd-pipeline-code-signing/17-CONTEXT.md
@.planning/phases/17-ci-cd-pipeline-code-signing/17-PATTERNS.md
@Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php
@Modules/Onboarding/Models/WizardProgress.php

<interfaces>
<!-- user_preferences table shape (minimal foundation) -->
Columns:
- id (auto-increment PK)
- user_id (FK users; cascadeOnDelete)
- created_at, updated_at (immutable timestamps)

Indexes:
- UNIQUE on user_id (one row per user)

NO feature columns in this migration. Subsequent plans add:
- skipped_update_versions JSON DEFAULT '[]' (Plan 17-04)
- counterparty_index_view VARCHAR(16) DEFAULT 'cards' (Plan 17-06b)

<!-- UserPreference Eloquent model -->
namespace Modules\Core\Models;
final class UserPreference extends Model {
    use BelongsToUser;
    protected $table = 'user_preferences';
    protected $fillable = ['user_id'];  // feature columns added to $fillable in their owning plans
    protected function casts(): array {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
</interfaces>
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: user_preferences table migration + UserPreference model + foundation test</name>
  <files>Modules/Core/Database/Migrations/XXXX_create_user_preferences_table.php, Modules/Core/Models/UserPreference.php, Modules/Core/tests/Feature/UserPreferencesTableTest.php</files>
  <read_first>
    - Modules/Categorization/Database/Migrations/2026_05_17_010003_create_categorization_rules_table.php (analog — module migration pattern with container-DI Migrator)
    - Modules/Onboarding/Models/WizardProgress.php (analog — BelongsToUser + fillable + casts())
    - Modules/Core/Database/Migrations/ (list existing migrations to derive a non-colliding timestamp)
  </read_first>
  <behavior>
    - Test 1: `php artisan migrate --pretend` runs cleanly with the new migration
    - Test 2: After migrate, the `user_preferences` table exists with columns: id, user_id, created_at, updated_at (exactly 4 — feature columns are added in later plans)
    - Test 3: Unique constraint on user_id is enforced (attempting to insert two rows for the same user_id raises a SQL exception)
    - Test 4: cascadeOnDelete from users: deleting a user removes the user_preferences row
    - Test 5: UserPreference model with BelongsToUser scope: querying as user A never returns user B's row (cross-user isolation)
  </behavior>
  <action>Step A — Migration: create `Modules/Core/Database/Migrations/<timestamp>_create_user_preferences_table.php` mirroring the Categorization-rules migration pattern. Use container-DI Migrator. Columns: id (auto-increment), user_id (unsignedBigInteger → FK users.id cascadeOnDelete), timestamps(). Unique constraint on user_id. No additional columns — feature plans add their own via additive migrations after this lands.

    Step B — Model: create `Modules/Core/Models/UserPreference.php` matching the interface contract above. `final class UserPreference extends Model` with `BelongsToUser` trait, `$table='user_preferences'`, `$fillable=['user_id']` (downstream plans extend), `casts()` returning immutable datetime for created_at + updated_at. PHPDoc describes WHAT the model is for in present tense — never reference subsequent plans by name.

    Step C — Pest test: write `Modules/Core/tests/Feature/UserPreferencesTableTest.php` with `RefreshDatabase`, covering all 5 behaviors above. Use direct query-builder inserts for the unique-constraint test (Test 3); use Eloquent `UserPreference::query()->create([...])` for the BelongsToUser test (Test 5) with a beforeEach that authenticates as user A.

    DI-only throughout. PHPDocs describe present-tense steady-state. No GSD vocabulary.</action>
  <verify>
    <automated>php artisan migrate --pretend && vendor/bin/pest Modules/Core/tests/Feature/UserPreferencesTableTest.php --stop-on-failure</automated>
  </verify>
  <done>All 5 behavior tests pass; migration runs cleanly via `--pretend` and applied to a fresh test DB; UserPreference model declares BelongsToUser + fillable + casts; Larastan + Pint green.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| consumer code → UserPreference model | Multiple modules will read/write this table; BelongsToUser is the single contract that keeps cross-user isolation honest |
| user_preferences row → cross-user leak | Per-user single-row; unique constraint + BelongsToUser scope prevent enumeration |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-17-04a-01 | Information disclosure | cross-user preference leak | mitigate | BelongsToUser scope + Test 5 cross-user-isolation verification |
| T-17-04a-02 | Tampering | downstream plan adding a column that breaks the unique-on-user_id contract | mitigate | Downstream migrations are additive (column-add only); they cannot drop the unique constraint without explicit acknowledgment in their own plan threat models |
</threat_model>

<verification>
After Task 1:

1. `vendor/bin/pest Modules/Core/tests/Feature/UserPreferencesTableTest.php` green (5 tests)
2. `php artisan migrate:fresh` succeeds (user_preferences table materializes)
3. `composer test` green
</verification>

<success_criteria>
- All 5 must_haves true
- user_preferences table is the canonical per-user preference store
- No feature columns leak into this migration (kept minimal so downstream plans own their additive migrations)
- Cross-user isolation verified
</success_criteria>

<output>
Create `.planning/phases/17-ci-cd-pipeline-code-signing/17-04a-SUMMARY.md` capturing: the migration timestamp chosen, the exact column list, and a note that Plans 17-04 + 17-06b own the feature columns added on top.
</output>
