<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\FileOpenIntake;
use Modules\Desktop\Internal\Native\PendingFileIntent;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

/*
 * Feature tests for the OS file-open intent (PKG-06; D-01..D-04).
 *
 * Coverage:
 *   - FileOpenIntake (Task 2): the security boundary that validates the
 *     OS-supplied path against the csv/eml allow-list before emitting
 *     the Public FileOpenedFromOs event.
 *   - Cross-module routing (Task 3): .csv→Import staging, .eml→Receipts staging.
 *   - Pending intent survives login (Task 4 / D-04): a logged-out
 *     file-open is held in session until the user authenticates.
 *   - Single-instance focus (Task 4 / D-03): a running-instance file
 *     open feeds the same FileOpenIntake path as cold start.
 */

beforeEach(function (): void {
    $this->fixturesDir = storage_path('app/test-file-open-'.bin2hex(random_bytes(4)));
    mkdir($this->fixturesDir, 0700, true);
});

afterEach(function (): void {
    if (is_dir($this->fixturesDir)) {
        foreach (glob($this->fixturesDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->fixturesDir);
    }
});

it('emits FileOpenedFromOs with extension=csv for a real .csv path', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/bank-export.csv';
    file_put_contents($path, "date,amount\n2026-01-01,12.34");

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    Event::assertDispatched(
        FileOpenedFromOs::class,
        fn (FileOpenedFromOs $event): bool => $event->extension === 'csv'
            && $event->path === realpath($path),
    );
})->group('phase-15');

it('emits FileOpenedFromOs with extension=eml for a real .eml path', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/receipt.eml';
    file_put_contents($path, "From: a@b\nSubject: test\n\nhello");

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    Event::assertDispatched(
        FileOpenedFromOs::class,
        fn (FileOpenedFromOs $event): bool => $event->extension === 'eml'
            && $event->path === realpath($path),
    );
})->group('phase-15');

it('FileOpenIntake rejects an unsupported extension and emits no event', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    foreach (['malicious.exe', 'script.sh', 'noext'] as $name) {
        $path = $this->fixturesDir.'/'.$name;
        file_put_contents($path, 'payload');

        /** @var FileOpenIntake $intake */
        $intake = app(FileOpenIntake::class);
        $intake->receive($path);
    }

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake rejects a non-existent path and a directory', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);

    $intake->receive($this->fixturesDir.'/never-existed.csv');
    $intake->receive($this->fixturesDir); // a directory, not a file

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake canonicalizes a traversal path and rejects it when the realpath does not resolve', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);

    // A path with `..` segments that would escape the temp dir into a
    // non-existent target — realpath() returns false, so the intake
    // rejects.
    $intake->receive($this->fixturesDir.'/../../../etc/never-existed.csv');

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake accepts a small .eml file under the per-extension cap (IN-05)', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/small.eml';
    file_put_contents($path, "From: a@b\nSubject: x\n\nhello");

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    Event::assertDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake rejects a .eml file above the tighter per-extension eml cap (IN-05)', function (): void {
    // The phase-15 fix tightens the .eml cap from the old single
    // 50 MB constant to a 5 MB per-extension override. A 6 MB
    // synthetic .eml must now be rejected; the previous behaviour
    // would have accepted it.
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/oversize.eml';
    $sixMb = str_repeat('a', 6 * 1024 * 1024);
    file_put_contents($path, "From: a@b\nSubject: big\n\n".$sixMb);

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake accepts a .csv file up to the broader 50 MB cap (IN-05 regression guard)', function (): void {
    // The .csv cap stays at 50 MB. A 6 MB CSV — rejected as an .eml
    // above — must still be accepted as a .csv. The assertion guards
    // against an accidental over-tightening of the per-extension
    // override that would break legitimate bank-export imports.
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/big-export.csv';
    $sixMb = str_repeat('a', 6 * 1024 * 1024);
    file_put_contents($path, "date,amount\n".$sixMb);

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    Event::assertDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileStagingPage routes .csv FileOpenedFromOs to the import staging flow', function (): void {
    $user = User::query()->create([
        'username' => 'csv-routing-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($user);

    $path = $this->fixturesDir.'/asn-export.csv';
    file_put_contents($path, "date,amount\n2026-01-01,12.34");
    $realPath = (string) realpath($path);

    // Driving the Public event simulates the FileOpenIntake emission —
    // the listeners we are testing subscribe to it via the Dispatcher
    // bound in the providers, and they decide where the user lands.
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new FileOpenedFromOs(path: $realPath, extension: 'csv'));

    $response = $this->get('/desktop/file-staging');
    $response->assertOk();
    $response->assertSee('File received: asn-export.csv');
    $response->assertSee('Start import');
})->group('phase-15');

it('FileStagingPage routes .eml FileOpenedFromOs to the receipts staging flow', function (): void {
    $user = User::query()->create([
        'username' => 'eml-routing-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($user);

    $path = $this->fixturesDir.'/google-receipt.eml';
    file_put_contents($path, "From: a@b\nSubject: test\n\nhello");
    $realPath = (string) realpath($path);

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new FileOpenedFromOs(path: $realPath, extension: 'eml'));

    $response = $this->get('/desktop/file-staging');
    $response->assertOk();
    $response->assertSee('File received: google-receipt.eml');
    $response->assertSee('Start import');
})->group('phase-15');

it('FileStagingPage shows the empty state when no file resolves', function (): void {
    $user = User::query()->create([
        'username' => 'empty-state-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($user);

    $response = $this->get('/desktop/file-staging');
    $response->assertOk();
    $response->assertSee("We couldn't open that file");
})->group('phase-15');

it('pending intent survives login: file held across login round-trip', function (): void {
    // Step 1: while logged OUT, the OS hands Beatrax a file. The intake
    // route stores the validated intent in PendingFileIntent.
    $user = User::query()->create([
        'username' => 'pending-intent-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $path = $this->fixturesDir.'/pending.csv';
    file_put_contents($path, "date,amount\n2026-01-01,12.34");
    $realPath = (string) realpath($path);

    /** @var PendingFileIntent $intent */
    $intent = app(PendingFileIntent::class);
    $intent->remember($realPath, 'csv');

    expect($intent->pending())->not->toBeNull();

    // Step 2: the user logs in. The ContinuePendingFileIntentAfterLogin
    // listener receives Laravel's Login event and re-checks the intent
    // is still pending for this session.
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $this->actingAs($user);
    $events->dispatch(new Login('web', $user, false));

    // Step 3: after login, the user lands on the staging page bound to
    // the pending file — not the dashboard.
    $response = $this->get('/desktop/file-staging');
    $response->assertOk();
    $response->assertSee('File received: pending.csv');

    // The intent is consumed exactly once.
    expect($intent->pending())->toBeNull();
})->group('phase-15');

it('pending intent does not leak across users (cross-user safety)', function (): void {
    // User A's session holds a pending intent. User B logs in — the
    // continuation listener MUST NOT continue user A's intent.
    $userA = User::query()->create([
        'username' => 'cross-user-a',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $userB = User::query()->create([
        'username' => 'cross-user-b',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $path = $this->fixturesDir.'/cross-user.csv';
    file_put_contents($path, "x,y\n1,2");
    $realPath = (string) realpath($path);

    /** @var PendingFileIntent $intent */
    $intent = app(PendingFileIntent::class);

    // User A's session — store an intent.
    $this->actingAs($userA);
    $intent->remember($realPath, 'csv');

    // User B logs in on a different session. Flush the current
    // user-A session so the pending-intent store reflects user B's
    // empty session (PendingFileIntent is session-scoped).
    app('session.store')->flush();

    $this->actingAs($userB);
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new Login('web', $userB, false));

    // User B's empty session has no pending intent.
    expect($intent->pending())->toBeNull();

    $response = $this->get('/desktop/file-staging');
    $response->assertOk();
    $response->assertSee("We couldn't open that file");
})->group('phase-15');

it('stale pending intent is discarded; login proceeds normally', function (): void {
    $user = User::query()->create([
        'username' => 'stale-intent-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // A pending intent whose recorded path no longer resolves is stale
    // — for example, a flash drive that was unmounted between the
    // double-click and the login. The continuation listener
    // re-validates the path; an unresolvable one is discarded.
    /** @var PendingFileIntent $intent */
    $intent = app(PendingFileIntent::class);
    $intent->remember('/tmp/never-existed-'.bin2hex(random_bytes(4)).'.csv', 'csv');

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $this->actingAs($user);
    $events->dispatch(new Login('web', $user, false));

    // The stale intent is gone.
    expect($intent->pending())->toBeNull();

    // Visiting the staging page now shows the empty state — login did
    // not redirect off the dashboard because there was nothing to
    // continue.
    $response = $this->get('/desktop/file-staging');
    $response->assertOk();
    $response->assertSee("We couldn't open that file");
})->group('phase-15');

it('single instance file open focuses the existing window', function (): void {
    // Cross-OS contract: a running-instance file open feeds the SAME
    // FileOpenIntake pathway as a cold start. Both produce a
    // FileOpenedFromOs emission with the validated path + extension.
    // The Electron-side window focus + navigate is owned by
    // src/main/index.js; the PHP-side equivalence test is that the
    // intake function treats both inputs identically.
    Event::fake([FileOpenedFromOs::class]);

    $coldStartPath = $this->fixturesDir.'/cold-start.csv';
    file_put_contents($coldStartPath, "x,y\n1,2");

    $runningPath = $this->fixturesDir.'/running.csv';
    file_put_contents($runningPath, "x,y\n1,2");

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);

    // Cold start: intake receives the path once.
    $intake->receive($coldStartPath);

    // Already running, second double-click: intake receives the second
    // path through the same entry point — no special-case branch.
    $intake->receive($runningPath);

    Event::assertDispatched(FileOpenedFromOs::class, 2);
    Event::assertDispatched(
        FileOpenedFromOs::class,
        fn (FileOpenedFromOs $event): bool => $event->path === realpath($coldStartPath),
    );
    Event::assertDispatched(
        FileOpenedFromOs::class,
        fn (FileOpenedFromOs $event): bool => $event->path === realpath($runningPath),
    );
})->group('phase-15');

it('PendingFileIntent discards a malformed stored intent and clears the session', function (): void {
    // A stored row whose `path` is not a string (session tampering or a
    // shape mismatch from an older build) must be treated as absent:
    // pending() returns null and forgets the key so the next read is
    // clean rather than repeatedly re-parsing a broken row.
    /** @var PendingFileIntent $intent */
    $intent = app(PendingFileIntent::class);

    session()->put(PendingFileIntent::SESSION_KEY, [
        'path' => 1234,
        'extension' => 'csv',
    ]);

    expect($intent->pending())->toBeNull();
    expect(session()->has(PendingFileIntent::SESSION_KEY))->toBeFalse();
})->group('phase-15');

it('PendingFileIntent discards a stored intent whose extension is not allow-listed', function (): void {
    // A well-typed row whose extension falls outside the allow-list is
    // still rejected and cleared — the allow-list is re-checked on read,
    // not only when the intent is first remembered.
    /** @var PendingFileIntent $intent */
    $intent = app(PendingFileIntent::class);

    session()->put(PendingFileIntent::SESSION_KEY, [
        'path' => __FILE__,
        'extension' => 'exe',
    ]);

    expect($intent->pending())->toBeNull();
    expect(session()->has(PendingFileIntent::SESSION_KEY))->toBeFalse();
})->group('phase-15');
