---
phase: 11-operational-hardening
reviewed: 2026-05-19T12:00:00Z
depth: standard
files_reviewed: 47
files_reviewed_list:
  - Modules/Core/Database/Migrations/2026_05_20_010001_create_system_alerts_table.php
  - Modules/Core/Internal/Console/BackupDatabaseCommand.php
  - Modules/Core/Internal/Console/DoctorCommand.php
  - Modules/Core/Internal/Console/FailedJobsCommand.php
  - Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php
  - Modules/Core/Internal/Console/Probes/BootProbeState.php
  - Modules/Core/Internal/Console/Probes/Probe.php
  - Modules/Core/Internal/Console/Probes/ProbeResult.php
  - Modules/Core/Internal/Console/Probes/SynchronousModeProbe.php
  - Modules/Core/Internal/Console/Probes/WalModeProbe.php
  - Modules/Core/Internal/Console/RestoreDatabaseCommand.php
  - Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php
  - Modules/Core/Internal/Console/Support/DurationParser.php
  - Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php
  - Modules/Core/Internal/Providers/HealthCheckServiceProvider.php
  - Modules/Core/Models/SystemAlert.php
  - Modules/Core/Providers/CoreServiceProvider.php
  - Modules/Core/Public/Actions/AcknowledgeSystemAlert.php
  - Modules/Core/Public/Services/SystemAlertQuery.php
  - Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php
  - Modules/Core/Resources/views/livewire/system-alerts-banner.blade.php
  - Modules/Core/tests/Feature/AppBootHealthCheckTest.php
  - Modules/Core/tests/Feature/BackupCorruptionPathTest.php
  - Modules/Core/tests/Feature/BackupDatabaseCommandTest.php
  - Modules/Core/tests/Feature/BackupScheduleTest.php
  - Modules/Core/tests/Feature/DoctorCommandTest.php
  - Modules/Core/tests/Feature/FailedJobsCommandTest.php
  - Modules/Core/tests/Feature/Phase11AcceptanceTest.php
  - Modules/Core/tests/Feature/ReadmeOperationalDocsTest.php
  - Modules/Core/tests/Feature/RestoreDatabaseCommandTest.php
  - Modules/Core/tests/Feature/RestoreSuccessPathTest.php
  - Modules/Core/tests/Feature/SystemAlertsBannerTest.php
  - Modules/Core/tests/Unit/AcknowledgeSystemAlertTest.php
  - Modules/Core/tests/Unit/BackupRetentionPolicyTest.php
  - Modules/Core/tests/Unit/DoctorProbesTest.php
  - Modules/Core/tests/Unit/DurationParserTest.php
  - Modules/Core/tests/Unit/SystemAlertModelTest.php
  - Modules/Core/tests/Unit/SystemAlertQueryTest.php
  - Modules/Core/tests/Unit/SystemAlertsMigrationTest.php
  - resources/views/layouts/app.blade.php
  - routes/console.php
  - tests/Contracts/BoundaryArchTest.php
  - tests/Contracts/HorizonForceFlagTest.php
  - tests/Helpers/RealSqliteFixture.php
  - tests/Helpers/RealSqliteFixtureTest.php
findings:
  critical: 2
  warning: 7
  info: 6
  total: 15
status: fixed
fixed: 2026-05-19
fixes_applied: 15
---

# Phase 11: Code Review Report

**Reviewed:** 2026-05-19T12:00:00Z
**Depth:** standard
**Files Reviewed:** 47
**Status:** issues_found

## Summary

Phase 11 ships a coherent operational-hardening surface: VACUUM-INTO backups
with smart-skip + retention, integrity-checked restore behind triple safety
rails, doctor probes, failed-jobs prune, persistent banner, and a boot-time
PRAGMA drift listener. Cross-file architecture (BoundaryArchTest invariants,
the `system_alerts` JOIN guard, the Horizon force-flag invariant) is sound,
and integration tests hit real SQLite via `RealSqliteFixture` instead of
mocking the DB — the no-DB-mocking policy is honoured.

Two findings rise to BLOCKER severity:

1. **`base_path()` global-helper calls in two Core files** directly violate
   the user-mandated DI-only contract in CLAUDE.md
   (`feedback_laravel_di_only.md`). The Pest arch test only catches `Facades\*`
   imports, so these slipped past the gate. Both have clean fixes (an
   `Application $app` injection or a contextual binding mirroring
   `BackupDatabaseCommand::$backupsPath`).
2. **The smart-skip path in `db:backup` runs against a stale `glob()` of
   `.meta.json` files, but `glob()` can return `false` on permission errors,
   which the `(array) glob()` cast silently turns into `[false]`** —
   producing a single bogus candidate that `(string) $candidates[0] === ""`
   coerces, then `is_file('')` returns false and the function still answers
   `not skippable`. The current code is accidentally correct, but the
   defence is brittle and a downstream refactor that "simplifies" the
   filter to `count($candidates) > 0` would write a backup with a false
   negative skip. Tightening the boolean check is mandatory before this
   ships to the partner — backups are load-bearing.

Beyond the BLOCKERS, several WARNING-tier defects deserve attention before
sign-off: the corrupt-source alert message references a `.suspect` file
that was never produced; `BackupRetentionPolicy` throws an uncaught
`InvalidArgumentException` on a malformed-but-regex-passing date
(`2026-13-99` survives the digit regex and crashes Carbon's parser); the
post-chmod-failure branch leaves a misleading metadata path and immediately
deletes the only file the operator could inspect; the restore command's
`getLaravel()->make()` is an inconsistent shape vs. the backup command's
contextual binding; and the scheduler entry for `db:backup` does not pass
`--force`, which combined with the smart-skip means a quiet day will fail
the 48h freshness probe.

INFO-tier items capture minor code-quality observations: the inline
tool-version checks in `DoctorCommand` could move to the `Probe` interface
for symmetry; the migration uses raw `sprintf` interpolation for trigger
DDL when the values are static constants; the partial Blade view's
`unknown kind` fallback echoes `$alert->message` unescaped-through-`{{ }}`
which is safe because Blade default-escapes, but the variable can carry
operator-controlled text from `BootHealthCheckServiceProvider` and deserves
a comment confirming the safety boundary; the `BootProbeState` listener
captures `$app` and `$provider` by `use` instead of receiving them through
the dispatcher's argument resolver; the `BackupDatabaseCommand`'s
`writeSidecar` swallows `file_put_contents` / `rename` failures because
the `@` operator suppresses warnings without checking the return value;
and `Phase11AcceptanceTest::scandir(...)` is called with `[...]` array
unpacking against `array_filter(scandir(...))` which redundantly
intersects the same set.

## Critical Issues

### CR-01: Global `base_path()` helper call violates DI-only contract

**File:** `Modules/Core/Internal/Console/RestoreDatabaseCommand.php:89`
**Issue:** CLAUDE.md's `feedback_laravel_di_only.md` rule mandates "No
facade calls / global helpers" in module code. The `base_path()` helper
is a global function defined in `vendor/laravel/framework/.../helpers.php`
that internally resolves the singleton Application container — equivalent
to a hidden facade call. The BoundaryArchTest `noFacadeCallsFromCoreConsoleCommands`
grep only scans for `Illuminate\Support\Facades\` namespace usage, so this
helper invocation passed CI but breaks the user-mandated invariant. A
second occurrence sits in `Modules/Core/Providers/CoreServiceProvider.php:60`
inside the `core.backups_directory` singleton closure (same root cause,
same fix shape).

**Fix:**
```php
// RestoreDatabaseCommand.php — inject the Application container.
use Illuminate\Contracts\Foundation\Application;

public function __construct(
    private readonly Repository $config,
    private readonly DatabaseManager $db,
    private readonly Filesystem $files,
    private readonly Kernel $artisan,
    private readonly Clock $clock,
    private readonly Application $app,
) { parent::__construct(); }

// then in handle():
$downMarkerPath = $this->app->basePath('storage/framework/down');
```

For `CoreServiceProvider::register()` the closure already has `$this->app`
in scope:
```php
$this->app->singleton(
    'core.backups_directory',
    fn (): string => $this->app->basePath('storage/app/backups'),
);
```

(Note: `Application::basePath()` is a method on the container, not a
helper — the call shape passes the DI-only contract.) The arch test
should also be extended to grep for the half-dozen `*_path()` helpers so
the next regression fails CI.

### CR-02: `glob()` returning `false` silently coerces to `[false]` in smart-skip + retention pruning

**File:** `Modules/Core/Internal/Console/BackupDatabaseCommand.php:273,340`
**Issue:** Both `isSkippable()` and the test helper paths use the pattern
`(array) glob(...)`. PHP's `glob()` can return `false` on a permission
error or unreadable directory (the manual documents this — it is NOT
strictly `array|false`). The `(array) false` cast in PHP produces
`[false]`, not `[]`:

```php
php -r 'var_dump((array) false);'
// array(1) { [0] => bool(false) }
```

Today the smart-skip path accidentally survives because the subsequent
`if ($candidates === [])` check is bypassed, then `$candidates[0]` is
`false`, `(string) false === ''`, `is_file('') === false`, and the
function returns `not skippable`. The retention prune path in `BackupDatabaseCommand::pruneRetention()` uses
`$this->files->files($backupsDir)` so is unaffected, but the smart-skip
read is brittle: a future contributor "simplifying" the empty-check to
`if ($candidates) { … }` or `count($candidates) === 0` would skip an
actual backup. The downstream tests also use the same pattern
(`BackupDatabaseCommandTest.php:84,111,119,156`, etc.) so the latent
defect spreads through the test surface.

Add an explicit `false` check at every `glob()` site, or wrap in a small
helper. The smart-skip branch is the load-bearing case because writing
a stale backup is the only path that silently corrupts the recovery
story.

**Fix:**
```php
private function isSkippable(string $backupsDir, int $liveDataVersion): bool
{
    $matched = glob($backupsDir.DIRECTORY_SEPARATOR.'diederik-*.sqlite.meta.json');
    if ($matched === false || $matched === []) {
        return false;
    }
    rsort($matched);
    $newest = $matched[0];
    // … rest unchanged
}
```

Apply the same explicit-false guard in every test that uses
`(array) glob(...)` — the cast obscures permission errors that should
fail loudly.

## Warnings

### WR-01: Corrupt-source alert message references a `.suspect` file that was never produced

**File:** `Modules/Core/Internal/Console/BackupDatabaseCommand.php:85-95,376-392`
**Issue:** When `readDataVersion()` throws `PDOException` on a truncated
source, the catch arm at line 82 records:
```php
$basenameForAlert = 'diederik-'.$startedAt->format('Y-m-d-His').'.sqlite';
$destinationForAlert = $backupsDir.DIRECTORY_SEPARATOR.$basenameForAlert;
$this->recordCorruptAlert($destinationForAlert, $destinationForAlert.'.suspect', [...]);
```

Neither `$destinationForAlert` nor `$destinationForAlert.'.suspect'` ever
exists on disk because `VACUUM INTO` never ran. The resulting banner
message reads "Backup written at <time> failed integrity check. Inspect
diederik-YYYY-MM-DD-HHMMSS.sqlite.suspect" — pointing the operator at a
file they will never find, when the actual signal is "source DB is
corrupt; the backup never even started." The same issue affects line 138
(no-output-file branch) and line 154 (chmod failure, where the
destination is then immediately `delete()`-d, leaving the message
pointing to a deleted file).

**Fix:** Adapt `recordCorruptAlert()` to accept a `?string $suspectPath`
and branch the message on whether the suspect file exists:
```php
private function recordCorruptAlert(string $destination, ?string $suspectPath, array $metadata): void
{
    $message = $suspectPath !== null && $this->files->exists($suspectPath)
        ? sprintf('Backup written at %s failed integrity check. Inspect %s.',
            $this->clock->now()->format('d M Y · H:i'),
            basename($suspectPath))
        : sprintf('Backup attempted at %s aborted before any file was produced — source DB may be corrupt.',
            $this->clock->now()->format('d M Y · H:i'));
    // … then create the SystemAlert row with the correct message
}
```
Update the partial Blade template (`system-alert-message.blade.php`
`backup_corrupt` branch) to handle a missing `suspect_path` metadata key
gracefully.

### WR-02: `BackupRetentionPolicy` throws uncaught exception on a regex-passing but calendar-invalid date

**File:** `Modules/Core/Internal/Console/Support/BackupRetentionPolicy.php:118`
**Issue:** The filename regex `^diederik-(\d{4})-(\d{2})-(\d{2})-(\d{6})\.sqlite$`
is purely digit-based, so a filename like `diederik-2026-13-99-250000.sqlite`
(month 13, day 99, hour 25) passes the regex and reaches
`CarbonImmutable::parse('2026-13-99')`, which throws
`Carbon\Exceptions\InvalidFormatException`. Today the policy crashes
the entire backup command's retention sweep, which means the freshly-
written `.sqlite` + `.meta.json` survive but old backups stop being
pruned. The probability of a real operator dropping a file with month 13
into the backups directory is low, but the policy is documented as
"intentionally pure" and "fully unit-testable" — a single uncaught
exception breaks both promises.

**Fix:**
```php
try {
    $dow = CarbonImmutable::parse($entry['date_only'])->dayOfWeek;
} catch (Throwable) {
    // Calendar-invalid date (e.g. 2026-13-99) — treat as non-Sunday.
    continue;
}
```
Or, more defensively, validate the date components with `checkdate()`
in the initial regex loop and treat invalid dates the same as non-
matching filenames (always preserved).

### WR-03: `RestoreDatabaseCommand` reaches into the container via `getLaravel()->make()` instead of using contextual binding

**File:** `Modules/Core/Internal/Console/RestoreDatabaseCommand.php:233-247`
**Issue:** `BackupDatabaseCommand` is constructor-DI'd with `private readonly
string $backupsPath` via a contextual binding (`CoreServiceProvider.php:62-64`).
`RestoreDatabaseCommand` resolves the same binding by name through
`$this->getLaravel()->make('core.backups_directory')` — a Service Locator
anti-pattern that breaks the DI-only invariant the rest of the Core
module enforces. Two commands sharing the same dependency should share
the same wiring shape; the asymmetry makes the contract harder to
verify (a future arch test "every Core console command takes
`$backupsPath` via constructor DI" cannot be written cleanly while one
command hides the dependency behind `getLaravel()`).

**Fix:** Mirror the backup command's contextual binding for the restore
command:
```php
// CoreServiceProvider.php register()
$this->app->when(RestoreDatabaseCommand::class)
    ->needs('$backupsPath')
    ->give(fn () => $this->app->make('core.backups_directory'));

// RestoreDatabaseCommand.php
public function __construct(
    // … existing args …
    private readonly string $backupsPath,
) { parent::__construct(); }

private function backupsDirectory(): string
{
    if (! $this->files->isDirectory($this->backupsPath)) {
        $this->files->makeDirectory($this->backupsPath, 0o755, recursive: true, force: true);
    }
    return $this->backupsPath;
}
```

### WR-04: `db:backup` scheduled run omits `--force`, so a quiet day silently no-ops past the 48h freshness threshold

**File:** `routes/console.php:218-221`
**Issue:** The scheduled run is `Schedule::command('db:backup')` — no
`--force` flag. `db:backup` smart-skips when `PRAGMA data_version` hasn't
moved since the last backup, so on a day the operator does not touch
the app, no new `.meta.json` sidecar is written. Combined with
`BackupFreshnessProbe::STALE_AFTER_HOURS = 48`, two consecutive quiet
days will produce a warning-severity `system_alerts(backup_overdue)`
row — and the banner will pester the operator on the next login,
suggesting they "run php artisan db:backup", which will… still smart-
skip because `data_version` still hasn't moved.

This is a UX bug rather than a correctness bug, but it converts the
"calm dashboard" promise into a noisy false alarm. Two clean fixes:
either pass `--force` from the scheduler so a backup is always written
(retention will prune the duplicates), or have `BackupFreshnessProbe`
treat a `data_version`-unchanged state as "no new commits to back up" =
ok rather than overdue. The first is simpler.

**Fix:**
```php
Schedule::command('db:backup --force')
    ->name('db.backup-daily')
    ->dailyAt('03:00')
    ->withoutOverlapping(60);
```

### WR-05: Smart-skip uses lexicographic `rsort` on full paths instead of explicitly comparing the embedded timestamp

**File:** `Modules/Core/Internal/Console/BackupDatabaseCommand.php:281`
**Issue:** `rsort($candidates)` works because every candidate path
shares the same `$backupsDir/diederik-` prefix and the YYYY-MM-DD-HHMMSS
suffix sorts lexicographically. But the comment on line 278 says "the
timestamp embedded in the name sorts lexicographically" — true today,
fragile tomorrow. If a future refactor changes `$backupsDir` to a path
containing characters that vary across the candidate set (e.g.
per-user subdirs), or someone introduces a sibling format like
`diederik-import-20260519.sqlite`, the lexicographic sort no longer
matches chronological order and the wrong candidate becomes "newest" —
returning the wrong `data_version`, masking real DB writes.

**Fix:** Sort on the parsed timestamp explicitly:
```php
usort($candidates, static function (string $a, string $b): int {
    // Both basenames match diederik-YYYY-MM-DD-HHMMSS.sqlite.meta.json;
    // strcmp on the parsed substring is honest about what we are
    // sorting on.
    return strcmp(basename($b), basename($a));
});
```

### WR-06: `BackupFreshnessProbe` writes a fresh `system_alerts(backup_overdue)` row on every doctor run

**File:** `Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php:154-173`
**Issue:** The doc comment claims "the duplicate-suppression gate lives
at the service-query layer (the banner reads unacknowledged rows only
and groups by kind) so this writes unconditionally — accumulating a
per-doctor-run audit trail." But this is precisely the opposite of the
duplicate-suppression story that `HealthCheckServiceProvider::recordDriftAlert`
applies (1-hour recency check against existing unacknowledged rows of
the same kind). Running `php artisan diederik:doctor` 100 times before
acknowledging the row produces 100 banner entries — the banner does NOT
group by kind, it renders one card per row (see
`system-alerts-banner.blade.php:22 @foreach ($alerts as $alert)`).

The audit-trail intent is reasonable, but a stale audit trail behind
the user's back is not. Either (a) apply the same 1-hour suppression
the boot listener uses, or (b) update the banner blade to dedupe by
kind before rendering. (a) is the simpler fix and aligns with the boot
listener's behaviour.

**Fix:**
```php
private function recordOverdueAlert(?int $hoursOld): void
{
    try {
        $recent = SystemAlert::withoutGlobalScopes()
            ->where('kind', 'backup_overdue')
            ->whereNull('acknowledged_at')
            ->where('created_at', '>=', $this->clock->now()->subHour())
            ->exists();
        if ($recent) {
            return;
        }
        SystemAlert::create([ /* … existing payload … */ ]);
    } catch (Throwable) {
        // unchanged
    }
}
```

### WR-07: `BackupDatabaseCommand::writeSidecar()` ignores I/O failures

**File:** `Modules/Core/Internal/Console/BackupDatabaseCommand.php:317-327`
**Issue:**
```php
$prevUmask = umask(0o077);
try {
    file_put_contents($tmp, $payload);
    @chmod($tmp, 0o600);
    @rename($tmp, $sidecar);
    @chmod($sidecar, 0o600);
} finally {
    umask($prevUmask);
    if (is_file($tmp)) {
        @unlink($tmp);
    }
}
```

`file_put_contents` can return `false` on disk-full or permission
errors; the suppressed-error `@chmod`/`@rename` chain swallows the
failure. The function is declared `void` so the caller has no signal.
A backup that left the operator without a sidecar will silently fall
through to a stale smart-skip the next time `db:backup` runs (because
`isSkippable()` will read the previous sidecar's data_version, which
matches, and skip writing). Worse: the chmod 0600 may not have applied,
leaving a half-written sidecar group/world-readable.

**Fix:**
```php
if (file_put_contents($tmp, $payload) === false) {
    throw new RuntimeException('Failed to write backup sidecar to '.$tmp);
}
if (chmod($tmp, 0o600) === false) {
    @unlink($tmp);
    throw new RuntimeException('Failed to chmod sidecar tmp to 0600.');
}
if (rename($tmp, $sidecar) === false) {
    @unlink($tmp);
    throw new RuntimeException('Failed to rename sidecar tmp to final path.');
}
@chmod($sidecar, 0o600); // belt-and-braces, harmless if it returns false
```
Wrap the call site in `try/catch` and bridge to `recordCorruptAlert()`
on failure so the operator sees the issue in the banner instead of
silently losing the audit chain.

## Info

### IN-01: Inline tool checks in `DoctorCommand` could move to `Probe` for symmetry

**File:** `Modules/Core/Internal/Console/DoctorCommand.php:94-96`
**Issue:** The class docblock explicitly defers this migration ("The
legacy inline `reportTool()` … deliberately stay inline rather than
being migrated to the `Probe` interface") and acknowledges the
asymmetry. Wrapping each tool check (`PHP`, `Composer`, `SQLite`,
`Node`) in a `ComposerVersionProbe` / `NodeVersionProbe` / etc. would
make the command body a pure iteration over a single homogeneous list
and shrink the file. Non-blocking cleanup.

**Fix:** Convert each `reportTool()` call to its own `Probe`
implementation, register them in `CoreServiceProvider::register()`,
and reduce `DoctorCommand::handle()` to a single `foreach ($probes as
$probe)` loop. The label-width formatting in `reportProbe()` already
handles variable-length labels.

### IN-02: Migration uses `sprintf` for static trigger DDL where string concatenation reads cleaner

**File:** `Modules/Core/Database/Migrations/2026_05_20_010001_create_system_alerts_table.php:69-82`
**Issue:** `$allowedSeverities = "'info','warning','critical'";` is a
hard-coded constant, but the trigger statement uses `sprintf` to
interpolate it. There is no user input flowing through, so the
`sprintf` doesn't add safety — it just adds a layer of indirection.
Either inline the constant directly, or define
`const ALLOWED_SEVERITIES_SQL = "'info','warning','critical'";` and
heredoc the trigger.

**Fix:**
```php
$triggerInsert = <<<SQL
CREATE TRIGGER system_alerts_severity_check_insert
BEFORE INSERT ON system_alerts
FOR EACH ROW
WHEN NEW.severity NOT IN ('info','warning','critical')
BEGIN
    SELECT RAISE(ABORT, 'Invalid system_alerts.severity value');
END
SQL;
$connection->statement($triggerInsert);
// same shape for the UPDATE trigger
```

### IN-03: Blade default fallback echoes `$alert->message` — confirm the safety boundary in a comment

**File:** `Modules/Core/Resources/views/livewire/partials/system-alert-message.blade.php:57-58`
**Issue:** The `@default` arm renders `{{ $alert->message }}` for an
unknown kind. Blade default-escaping applies, so HTML/JS injection is
not possible. However, the `system_alerts.message` column is populated
from operator-controlled context in multiple places
(`BackupDatabaseCommand::recordCorruptAlert`,
`HealthCheckServiceProvider::recordDriftAlert`,
`BackupFreshnessProbe::recordOverdueAlert`, plus any future module
following the same pattern). A reader of the partial template will
notice the unescaped-looking copy and wonder if it's a typo. Adding a
single line of comment cements the intent and prevents a "fix" from
swapping to `{!! !!}` in a future polish PR.

**Fix:** Add a short note above the `@default` arm:
```blade
@default
    {{-- Blade default-escapes — message is operator text, never trust raw HTML. --}}
    {{ $alert->message }}
```

### IN-04: `HealthCheckServiceProvider` listener captures `$app` and `$provider` via `use` instead of method-arg DI

**File:** `Modules/Core/Internal/Providers/HealthCheckServiceProvider.php:51-54`
**Issue:** The Laravel event dispatcher does not resolve closure
parameters from the container the same way it resolves
listener-class `handle()` parameters. The listener correctly captures
`$state, $app, $provider` via the closure `use ()` clause to give it
access to the container and `recordDriftAlert`. That's fine and
intentional, but it produces a 50-line listener body that mixes
closure-scope captures with explicit `$app->make()` calls — a long
method that's harder to read than a small `__invoke`-able listener
class would be. Non-blocking refactor: introduce a private static
`HealthCheckListener` class with `__invoke(ConnectionEstablished $event)`
and constructor-inject `LoggerInterface`, `Clock`, `DatabaseManager`,
`BootProbeState`.

**Fix:** Extract the listener to its own final class under
`Modules/Core/Internal/Listeners/` so the provider's `boot()` becomes
`$events->listen(ConnectionEstablished::class, HealthCheckListener::class);`.

### IN-05: `FailedJobsCommand` `--older-than=0d` is accepted and deletes every row older than now

**File:** `Modules/Core/Internal/Console/Support/DurationParser.php:46`
**Issue:** The regex `^(\d+)([dhw])$` accepts `0d`, `0h`, `0w` — all
of which resolve to `now->subDays(0)` = `now`. Combined with the
prune predicate `where('failed_at', '<', $cutoffString)`, a user
typing `diederik:failed-jobs prune --older-than=0d` will delete every
failed job row regardless of age. The interactive operator's mental
model of "older than zero days" might be "delete everything," but a
typo of `0d` instead of `30d` would be catastrophic. The dry-run
default does mitigate this somewhat.

**Fix:** Reject zero amounts at the parser level:
```php
$amount = (int) $matches[1];
if ($amount <= 0) {
    throw new InvalidArgumentException(
        "Duration must be a positive integer (got '{$input}')."
    );
}
```

### IN-06: `Phase11AcceptanceTest` uses `scandir(...)` against `array_filter(scandir(...))` redundantly

**File:** `Modules/Core/tests/Feature/Phase11AcceptanceTest.php:129-132`
**Issue:**
```php
expect(scandir($backupsDir))->toContain(...array_filter(
    scandir($backupsDir),
    static fn (string $name): bool => str_starts_with($name, 'diederik-'),
));
```

This asserts "`scandir(...)` contains every element of
`array_filter(scandir(...))`" — which is trivially true (a set
contains all of its filtered subset by definition). The assertion
adds no signal. Either tighten to a useful invariant ("at least one
`diederik-*` file exists") or remove. Subsequent assertions on
`$cleanSqliteFiles` and `$cleanMetaFiles` already cover the useful
property.

**Fix:**
```php
$diederikEntries = array_values(array_filter(
    scandir($backupsDir),
    static fn (string $name): bool => str_starts_with($name, 'diederik-'),
));
expect($diederikEntries)->not->toBe([], 'Happy run must produce diederik-* artifacts.');
```

---

_Reviewed: 2026-05-19T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
