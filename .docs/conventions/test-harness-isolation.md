# What the root TestCase isolates, and why

`tests/TestCase.php` is the base class every test in the repository ultimately
extends, module-local `TestCase`s included. Almost everything in its `setUp()`
exists because a test failed in a way that had nothing to do with the code under
test: a leftover file, a service the harness does not provision, or another
process writing to the same directory.

This page explains each override, so the class itself does not have to and so
the next person debugging a mysterious failure recognises the shape.

## The problem `RefreshDatabase` does not solve

`RefreshDatabase` rolls the database back between tests. It leaves the disk
exactly as it found it. That asymmetry is the source of most of what follows.

The sharpest example: the sync keyring is written to a file named for the user
id, and `RefreshDatabase` restarts those ids from 1 in every test. A test would
therefore find the *previous* test's encrypted keyring sitting at the path for
"its" user, fail to decrypt it with a session that had never held that key, and
fail — or pass — depending only on what ran before it.

The fix is a private storage root per test:

```php
$this->isolatedStorageRoot = sys_get_temp_dir()
    .DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8))
    .DIRECTORY_SEPARATOR.'storage';
```

**Isolating beats purging.** An earlier attempt deleted keyrings between tests
and broke the writer, which stages a file and renames it into place — the delete
raced the rename. An empty root of its own leaves nothing to delete and nothing
to race.

A test that sandboxes the root itself keeps working: its `beforeEach` runs after
this one and wins, and `tearDown` only removes what it created. `removeTree()`
refuses to walk anything outside the system temp directory, so a mis-set root can
never reach a real tree.

### Three path APIs, all of which have to agree

Relocating the storage root means moving it in every API that resolves one, and
there are three:

1. **`NATIVEPHP_STORAGE_PATH`** — the environment variable
   `Modules\Core\Public\Services\UserDataPathService` reads. Production code
   resolves paths through that service.
2. **`$app->useStoragePath()`** — what Laravel's `storage_path()` helper returns,
   which is what test assertions use. Moving only one of the two splits them, and
   the assertion then inspects a directory the code never wrote to.
3. **`filesystems.disks.*.root`** — the one that is easy to miss. Disk roots are
   resolved from `storage_path()` when the config *loads*, which happens before
   `useStoragePath()` moves it. So `Storage::disk('local')` went on writing into
   the real tree while everything else relocated.

The third one bites hardest under `--parallel`, where the real tree is a single
directory shared by every worker process. The import staging path is the content
hash beneath the user id — and both of those repeat across the workers' isolated
databases — so processes overwrote each other's staged uploads mid-read.

`view.compiled` is moved for the same reason, and the framework subdirectories
(`app`, `logs`, `framework/cache`, `framework/sessions`, `framework/views`) are
created up front, because view compilation and the log channel need somewhere to
write.

## Services the harness does not provision

**Redis.** `Cache::driver('redis')` is redirected to the `array` driver. Laravel's
`UniqueLock` machinery calls `$job->uniqueVia()` unconditionally at dispatch —
including on the `sync` queue driver — so any `ShouldBeUniqueUntilProcessing` job
opens a cache connection whether or not the test cares. Without the redirect,
every `ConfirmImport` feature test fails with
`Connection refused [tcp://127.0.0.1:6379]`.

Tests that genuinely need Redis (`HorizonBootsTest`) check the connection up
front and skip when it is unreachable. They talk to Redis through the predis
client directly, so the cache-store redirect does not interfere.

**The `cache_locks` table.** The shipped `cache.locks_store` default is
`database`, whose lock repository writes to `cache_locks`. Unit tests do not run
migrations, so the same `uniqueVia()` call fails with
`no such table: cache_locks`. `cache.locks_store` is therefore set to `array`
too. A test that exercises the real database lock store
(`DatabaseQueueConcurrencyTest`) sets it back to `database` in its own
`beforeEach()`.

**A running Vite dev server.** Vite chooses between manifest URLs and dev-server
URLs by looking for `public/hot`, a file the dev server writes while it runs.
With the desktop app up, every rendered-HTML assertion in the suite went red —
asset URLs came back as `http://[::1]:5174/…` — for exactly as long as the server
was running. `useHotFile()` is pointed at a path inside the isolated root that
nothing ever creates.

## The shared fixture seed

`seedFixtureUserAndAccount()` lives on the root `TestCase` so the cross-module
`IdempotencyContractTest` and the Import module's `AsnCsvImportTest` resolve the
same canonical user and account rows. It seeds one user plus three accounts,
each keyed by an IBAN that is load-bearing somewhere else:

| IBAN | Why this exact literal |
|---|---|
| `NL57ASNB0123456789` | The anonymisation value baked into `tests/fixtures/asn-sample-1.csv`; `EloquentAccountResolver` looks it up directly. Changing it breaks the CSV fixture. |
| `ICS-CARD` | The synthetic own-IBAN literal `IcsPdfAdapter` emits for every parsed ICS PDF row. |
| `PAYPAL` | The synthetic own-IBAN literal `PaypalCsvAdapter` emits for every parsed PayPal Activity Download row. |

The two synthetic literals are instance-wide rather than per-user because
`AccountResolver` already scopes its lookups by `user_id`.

The user is typed as `App\Models\User` rather than `Modules\Core\Models\User`.
Both are the same class: `CoreServiceProvider` registers the alias so framework
consumers that expect the default Laravel namespace —
`auth.providers.users.model`, notification routing — resolve the module model.

## Related

- [Module boundaries](../architecture/module-boundaries.md) — where module-local
  test suites live
- [Arch invariants](arch-invariants.md) — the other half of `tests/Contracts/`
