<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Native\PendingFileIntent;
use Modules\Desktop\Internal\Native\ShellHandoff;
use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;
use Native\Desktop\Events\App\OpenFile;

// The second instance of the same defect. HandleNativeOpenFile reaches session
// state two hops down -- through FileOpenIntake, FileOpenedFromOs and the
// Import/Receipts listeners -- so a one-level read of the listener misses it,
// and a double-clicked bank export was validated, remembered nowhere, and
// answered with the dashboard.

// The same boundary the lock suite restores, for the same reason: one store
// serves every request in-process, so what the shell's own request left in
// memory has to be dropped before the window's next request is judged.
function windowSessionAfterTheShellOpenedAFile(Session $session, string $id): void
{
    $session->flush();
    $session->setId($id);
    $session->start();
}

beforeEach(function (): void {
    $this->fixturesDir = storage_path('app/test-os-file-open-'.bin2hex(random_bytes(4)));
    mkdir($this->fixturesDir, 0700, true);

    $this->export = $this->fixturesDir.'/bank-export.csv';
    file_put_contents($this->export, "date,amount\n2026-01-01,12.34\n");

    $this->user = User::query()->create([
        'username' => 'os-file-open',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

afterEach(function (): void {
    foreach (glob($this->fixturesDir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->fixturesDir);
});

it('carries a double-clicked export into the session the window reads', function (): void {
    $this->actingAs($this->user);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $session->save();
    $windowSessionId = $session->getId();

    $this->post('_native/api/events', [
        'event' => OpenFile::class,
        'payload' => [$this->export],
    ])->assertOk();

    windowSessionAfterTheShellOpenedAFile($session, $windowSessionId);

    expect($session->get(PendingFileIntent::SESSION_KEY))->toBeNull(
        'The shell has no session to remember an intent in.',
    );

    $this->get(route('dashboard'))->assertRedirect(route('desktop.file-staging'));

    /** @var PendingFileIntent $intent */
    $intent = $this->app->make(PendingFileIntent::class);

    expect($intent->pending())->toBe([
        'path' => realpath($this->export),
        'extension' => 'csv',
    ]);
});

it('waits for a reader rather than staging into a session nobody is signed into', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $session->save();
    $windowSessionId = $session->getId();

    $this->post('_native/api/events', [
        'event' => OpenFile::class,
        'payload' => [$this->export],
    ])->assertOk();

    windowSessionAfterTheShellOpenedAFile($session, $windowSessionId);

    $this->get(route('login'))->assertOk();

    $this->actingAs($this->user);

    $this->get(route('dashboard'))->assertRedirect(route('desktop.file-staging'));
});

it('refuses a document type the intake does not route', function (): void {
    $rejected = $this->fixturesDir.'/notes.txt';
    file_put_contents($rejected, 'nothing to stage');

    $this->actingAs($this->user);

    $this->post('_native/api/events', [
        'event' => OpenFile::class,
        'payload' => [$rejected],
    ])->assertOk();

    /** @var PendingFileIntent $intent */
    $intent = $this->app->make(PendingFileIntent::class);

    expect($intent->pending())->toBeNull();
});

// The write half carries its own extension gate rather than trusting the intake
// that normally precedes it: it is the boundary the Import and Receipts
// listeners reach across, and either could hand it something else. Asserted on
// the slot, not on pending(), because canonicalize() would refuse the same value
// downstream and a test reading that far cannot tell which gate answered.
it('leaves nothing at all for an extension the intake does not route', function (): void {
    app(RemembersPendingFileIntent::class)->remember($this->export, 'exe');

    /** @var ShellHandoff $handoff */
    $handoff = $this->app->make(ShellHandoff::class);

    expect($handoff->take(ShellHandoff::FILE_INTENT))->toBeNull();
});
