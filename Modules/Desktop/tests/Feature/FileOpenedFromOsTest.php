<?php

declare(strict_types=1);

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\FileOpenIntake;
use Modules\Desktop\Internal\Native\PendingFileIntent;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

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
    $intake->receive($this->fixturesDir);

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake canonicalizes a traversal path and rejects it when the realpath does not resolve', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);

    // The `..` segments resolve to a target that does not exist, so realpath()
    // returns false and the intake rejects before any allow-list check.
    $intake->receive($this->fixturesDir.'/../../../etc/never-existed.csv');

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake accepts a small .eml file under the per-extension cap', function (): void {
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/small.eml';
    file_put_contents($path, "From: a@b\nSubject: x\n\nhello");

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    Event::assertDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake rejects a .eml file above the tighter per-extension eml cap', function (): void {
    // The .eml cap is 5 MB where the general cap is 50 MB, so 6 MB is the size
    // that tells the per-extension override apart from the old single constant.
    Event::fake([FileOpenedFromOs::class]);

    $path = $this->fixturesDir.'/oversize.eml';
    $sixMb = str_repeat('a', 6 * 1024 * 1024);
    file_put_contents($path, "From: a@b\nSubject: big\n\n".$sixMb);

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    Event::assertNotDispatched(FileOpenedFromOs::class);
})->group('phase-15');

it('FileOpenIntake accepts a .csv file up to the broader 50 MB cap', function (): void {
    // The same 6 MB rejected as an .eml must still pass as a .csv: over-tightening
    // the per-extension override would break legitimate bank-export imports.
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

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $this->actingAs($user);
    $events->dispatch(new Login('web', $user, false));

    $response = $this->get('/desktop/file-staging');
    $response->assertOk();
    $response->assertSee('File received: pending.csv');

    expect($intent->pending())->toBeNull();
})->group('phase-15');

it('pending intent does not leak across users (cross-user safety)', function (): void {
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

    $this->actingAs($userA);
    $intent->remember($realPath, 'csv');

    // PendingFileIntent is session-scoped, so flushing user A's session is how
    // the test models user B arriving on a session of their own.
    app('session.store')->flush();

    $this->actingAs($userB);
    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $events->dispatch(new Login('web', $userB, false));

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

    // The recorded path can stop resolving between the double-click and the
    // login — an unmounted flash drive — so the listener re-validates it.
    /** @var PendingFileIntent $intent */
    $intent = app(PendingFileIntent::class);
    $intent->remember('/tmp/never-existed-'.bin2hex(random_bytes(4)).'.csv', 'csv');

    /** @var Dispatcher $events */
    $events = app(Dispatcher::class);
    $this->actingAs($user);
    $events->dispatch(new Login('web', $user, false));

    expect($intent->pending())->toBeNull();

    $response = $this->get('/desktop/file-staging');
    $response->assertOk();
    $response->assertSee("We couldn't open that file");
})->group('phase-15');

it('single instance file open focuses the existing window', function (): void {
    // The window focus and navigate live in src/main/index.js, out of reach
    // here. What PHP can pin is that a second double-click into a running
    // instance goes through the same intake as a cold start.
    Event::fake([FileOpenedFromOs::class]);

    $coldStartPath = $this->fixturesDir.'/cold-start.csv';
    file_put_contents($coldStartPath, "x,y\n1,2");

    $runningPath = $this->fixturesDir.'/running.csv';
    file_put_contents($runningPath, "x,y\n1,2");

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);

    $intake->receive($coldStartPath);

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
    // A non-string path means session tampering or a row shape from an older
    // build; pending() forgets the key as well as returning null, so the next
    // read is not re-parsing the same broken row.
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
    // The allow-list is re-checked on read, not only when the intent is first
    // remembered, so a tampered session row cannot smuggle an extension through.
    /** @var PendingFileIntent $intent */
    $intent = app(PendingFileIntent::class);

    session()->put(PendingFileIntent::SESSION_KEY, [
        'path' => __FILE__,
        'extension' => 'exe',
    ]);

    expect($intent->pending())->toBeNull();
    expect(session()->has(PendingFileIntent::SESSION_KEY))->toBeFalse();
})->group('phase-15');

// The chain ended one step short: the path was validated and remembered, and
// then the reader was shown whatever they had asked for. Nothing in the product
// ever navigated to desktop.file-staging — only the tests did.

it('takes the reader to the staged file instead of the page they asked for', function (): void {
    $user = User::query()->create([
        'username' => 'staging-navigation-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($user);

    $path = $this->fixturesDir.'/double-clicked.csv';
    file_put_contents($path, "date,amount\n2026-01-01,12.34");

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    $this->get(route('dashboard'))->assertRedirect(route('desktop.file-staging'));
});

it('lets the reader carry on once the staged file has been shown', function (): void {
    $user = User::query()->create([
        'username' => 'staging-once-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($user);

    $path = $this->fixturesDir.'/shown-once.csv';
    file_put_contents($path, "date,amount\n2026-01-01,12.34");

    /** @var FileOpenIntake $intake */
    $intake = app(FileOpenIntake::class);
    $intake->receive($path);

    $this->get(route('desktop.file-staging'))->assertOk();

    // Viewing the staging page consumes the intent, so the reader is not sent
    // back to it on every navigation for the rest of the session.
    expect($this->get(route('dashboard'))->headers->get('Location'))
        ->not->toBe(route('desktop.file-staging'));
});

it('never sends a signed-out reader to a staging screen the auth gate bounces straight back', function (): void {
    // An install with an owner, so the first-launch gate is not what answers.
    User::query()->create([
        'username' => 'signed-out-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $path = $this->fixturesDir.'/signed-out.csv';
    file_put_contents($path, "date,amount\n2026-01-01,12.34");

    app(PendingFileIntent::class)->remember((string) realpath($path), 'csv');

    // The staging route is behind `auth`, so a redirect here would bounce back
    // to login and be redirected again, forever.
    expect($this->get(route('login'))->headers->get('Location'))
        ->not->toBe(route('desktop.file-staging'));
});
