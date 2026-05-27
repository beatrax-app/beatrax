# Deferred items — Phase 16.1.2.1

Out-of-scope discoveries logged during plan execution. Do not fix as part of
the originating plan; surface as a Phase-level concern.

## From Plan 01 execution

### Worktree parallel-worker PHPStan memory exhaustion

- **Symptom:** `./vendor/bin/phpstan analyse` parent process succeeds but spawned parallel-worker children crash with `Allowed memory size of 134217728 bytes exhausted in vendor/composer/autoload_static.php`.
- **Workaround used:** Invoking with `--debug` switches PHPStan to serial mode, which sidesteps the worker fork and completes cleanly (`[OK] No errors`).
- **Repro:** Inside the agent-spawned worktree at `.claude/worktrees/agent-*`. The main repository at `/Users/wesselverheij/Development/diederik` runs the same command on the same lockfile without issue.
- **Likely cause:** The very long worktree path or a missing realpath-cache prewarm in the worker subprocess inflates the composer autoload-class-map fingerprint just enough to break the 128M default `memory_limit` of the worker. The parent process inherits the explicit `--memory-limit=1G` flag; the worker fork does not.
- **Action:** Pre-existing worktree-only infra concern. Out of scope for Plan 01.

### Worktree Vite-manifest absence breaks 4 Livewire HTTP view tests

- **Symptom:** `Modules\Categorization\tests\Feature\TriagePageTest` — 4 tests fail with `Vite manifest not found at .../public/build/manifest.json` while rendering `resources/views/layouts/app.blade.php` through Livewire.
- **Repro:** Worktree only — `public/build/` is gitignored, so the fresh worktree never has the built assets the test harness expects to read.
- **Confirmed:** Main repo `public/build/manifest.json` is present and the same tests pass there.
- **Action:** Pre-existing worktree-only infra concern. Out of scope for Plan 01 (the failing tests neither read nor write any seeder / rule / categorization code paths).
