<?php

declare(strict_types=1);

use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;

// This gates the failure notice, so a null status — no attempt has run yet —
// must not render as a failure the user cannot explain.
it('reports a failed last attempt for every stored status that is not ok', function (?string $status, bool $failed): void {
    expect(SyncAttemptStatus::failedIn($status))->toBe($failed);
})->with([
    'never attempted' => [null, false],
    'blank column' => ['', false],
    'succeeded' => ['ok', false],
    'errored' => ['error', true],
    'consent failed' => ['consent_failed', true],
    'stopped early' => ['truncated', true],
    'filed nothing' => ['nothing_imported', true],
    // A value no release ever wrote is still not a success.
    'unrecognised' => ['something-else', true],
]);

it('spells every status the connection column carries', function (): void {
    expect(array_map(
        static fn (SyncAttemptStatus $case): string => $case->value,
        SyncAttemptStatus::cases(),
    ))->toBe(['ok', 'error', 'consent_failed', 'truncated', 'nothing_imported']);
});
