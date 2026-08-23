<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */

// Found on an iPhone 12 mini with encryption switched OFF, which is the
// default install: a cash entry saved with a 300-character counterparty came
// back with the name gone. The row was intact in the device's own SQLite —
// length 300 — and the span the phone drew for it was empty and 0px tall.

function base64ShapedCodecUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('base64-shaped-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Every one of these is drawn only from the base64 alphabet and decodes to 40
// bytes or more, so the shape test called all of them ciphertext.
dataset('plaintext that decodes as base64', [
    'the 300 characters typed on the phone' => [str_repeat('K', 300)],
    'a run-together bank description' => ['AbonnementSpotifyPremiumFamilyPlanMaandelijkseIncassoRotterdam'],
    'the shortest value that reached the length gate' => [str_repeat('K', 54)],
]);

it('hands back a value it never encrypted, whatever that value looks like', function (string $stored): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = base64ShapedCodecUser('never-enrolled-'.substr(md5($stored), 0, 8));

    $read = $codec->decryptValue('transactions', 'counterparty_name', $stored, (int) $user->id, $session);

    expect($read['value'])->toBe($stored);
})->with('plaintext that decodes as base64');

it('does not report that value as unreadable', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = base64ShapedCodecUser('never-enrolled-row');
    $stored = str_repeat('K', 300);

    $row = $codec->decryptRow(
        'transactions',
        ['counterparty_name' => $stored],
        (int) $user->id,
        $session,
    );

    expect($row['counterparty_name'])->toBe($stored)
        ->and($row->isUnreadable('counterparty_name'))->toBeFalse()
        ->and($row->hasUnreadable())->toBeFalse();
});

// The case the shape test exists for, and the one that must NOT follow the
// rule above: encryption really is on, this device simply cannot open the
// value, and base64 on the screen is what that used to look like.
it('still blanks ciphertext for a user whose ledger is sealed', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = base64ShapedCodecUser('sealed-and-unreachable');

    $keyring->generateAndPersist($user->id, $session);
    $ciphertext = $codec->encryptValue('transactions', 'counterparty_name', 'ALBERT HEIJN', $user->id, $session);

    AppLockTestHarness::unlock($session, str_repeat("\x7f", 32));
    $this->app->forgetInstance(GdkKeyringService::class);
    $this->app->forgetInstance(SensitiveColumnCodec::class);

    /** @var SensitiveColumnCodec $fresh */
    $fresh = $this->app->make(SensitiveColumnCodec::class);

    $read = $fresh->decryptValue('transactions', 'counterparty_name', $ciphertext, (int) $user->id, $session);

    expect($read['value'])->toBe('')
        ->and($read['decrypted'])->toBeFalse();
});

// A sealed ledger the device CAN open must still round-trip, or the enrolment
// question above would have replaced one silent loss with another.
it('still decrypts normally for a user whose key is held', function (): void {
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var GdkKeyringService $keyring */
    $keyring = $this->app->make(GdkKeyringService::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $user = base64ShapedCodecUser('sealed-and-readable');
    $stored = str_repeat('K', 300);

    $keyring->generateAndPersist($user->id, $session);
    $ciphertext = $codec->encryptValue('transactions', 'counterparty_name', $stored, $user->id, $session);

    $read = $codec->decryptValue('transactions', 'counterparty_name', $ciphertext, (int) $user->id, $session);

    expect($read['value'])->toBe($stored)
        ->and($read['decrypted'])->toBeTrue();
});
