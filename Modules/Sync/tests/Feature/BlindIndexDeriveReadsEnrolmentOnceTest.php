<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\BlindIndexCodec;

uses(RefreshDatabase::class);

// derive() runs once per registered column per row, so an import of a year's
// statements asks this question tens of thousands of times. It asked it twice
// each time: once to decide whether the user is enrolled, and once more on the
// way to the key, through a public method that has to re-check for its own
// callers.

function blindIndexEnrolmentUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('blind-index-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return list<string>
 */
function enrolmentReadsDuring(DatabaseManager $db, callable $work): array
{
    $connection = $db->connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $work();
    } finally {
        $connection->disableQueryLog();
    }

    $reads = [];
    foreach ($connection->getQueryLog() as $entry) {
        $sql = is_string($entry['query'] ?? null) ? $entry['query'] : '';
        if (str_contains($sql, 'sync_encryption_state')) {
            $reads[] = $sql;
        }
    }

    return $reads;
}

it('reads the enrolment state once per derive, not once per caller that asks', function (): void {
    $userId = (int) blindIndexEnrolmentUser('blind-index-enrolment')->id;

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var BlindIndexCodec $codec */
    $codec = $this->app->make(BlindIndexCodec::class);

    $keyringService->generateAndPersist($userId, $session);
    $codec->derive(BlindIndexCodec::DOMAIN_COUNTERPARTY_NORMALIZED, 'warm the keyring', $userId, $session);

    $reads = enrolmentReadsDuring($db, function () use ($codec, $userId, $session): void {
        $codec->derive(BlindIndexCodec::DOMAIN_COUNTERPARTY_NORMALIZED, 'ALBERT HEIJN 1042', $userId, $session);
    });

    expect($reads)->toHaveCount(1);
});

// The saving must not come from the enrolment check being skipped: an
// unenrolled reader's plaintext has to keep coming back unchanged, and that
// decision is the one read this test allows.
it('still returns the plaintext unchanged for a reader who never enabled encryption', function (): void {
    $userId = (int) blindIndexEnrolmentUser('blind-index-unenrolled')->id;

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var BlindIndexCodec $codec */
    $codec = $this->app->make(BlindIndexCodec::class);

    expect($codec->derive(BlindIndexCodec::DOMAIN_COUNTERPARTY_NORMALIZED, 'ALBERT HEIJN 1042', $userId, $session))
        ->toBe('ALBERT HEIJN 1042');
});
