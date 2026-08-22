<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

uses(RefreshDatabase::class);

// The Sync TestCase primes the session with a real (dummy) app-lock KEK, so the
// keyring crypto path runs for real here instead of being stubbed out.

function codecUser(string $username = 'codec-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('codec-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('encrypts a sensitive value under the current epoch and decrypts it back (round-trip)', function (): void {
    $userId = (int) codecUser('codec-roundtrip')->id;

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $keyringService->generateAndPersist($userId, $session);

    $ciphertext = $codec->encryptValue('transactions', 'note', 'a very private note', $userId, $session);
    expect($ciphertext)->not->toBe('a very private note');

    $result = $codec->decryptValue('transactions', 'note', $ciphertext, $userId, $session);
    expect($result['decrypted'])->toBeTrue();
    expect($result['value'])->toBe('a very private note');
});

it('still decrypts an epoch-1 ciphertext after rotating to epoch 2 (try-every-epoch, rotation-safe)', function (): void {
    $userId = (int) codecUser('codec-rotation')->id;

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $epoch1 = $keyringService->generateAndPersist($userId, $session);

    $epoch1Ciphertext = $codec->encryptValue('transactions', 'description', 'epoch-1 merchant text', $userId, $session);

    // Appending also advances current_epoch, so encrypts after this use epoch 2.
    $epoch2 = new GdkEpoch(epochId: $epoch1->epochId + 1, keyHex: bin2hex(random_bytes(32)));
    $keyringService->appendEpoch($userId, $epoch2, $session);

    $epoch2Ciphertext = $codec->encryptValue('transactions', 'description', 'epoch-2 merchant text', $userId, $session);
    expect($epoch2Ciphertext)->not->toBe($epoch1Ciphertext);

    $decryptedOld = $codec->decryptValue('transactions', 'description', $epoch1Ciphertext, $userId, $session);
    expect($decryptedOld['decrypted'])->toBeTrue();
    expect($decryptedOld['value'])->toBe('epoch-1 merchant text');

    $decryptedNew = $codec->decryptValue('transactions', 'description', $epoch2Ciphertext, $userId, $session);
    expect($decryptedNew['decrypted'])->toBeTrue();
    expect($decryptedNew['value'])->toBe('epoch-2 merchant text');
});

it('returns the raw value + decrypted:false for a tampered ciphertext, and never throws', function (): void {
    $userId = (int) codecUser('codec-tamper')->id;

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $keyringService->generateAndPersist($userId, $session);

    $ciphertext = $codec->encryptValue('counterparties', 'display_name', 'Acme Corp', $userId, $session);
    $tampered = substr($ciphertext, 0, -4).'xxxx';

    $result = $codec->decryptValue('counterparties', 'display_name', $tampered, $userId, $session);
    expect($result['decrypted'])->toBeFalse();
    // Blanked, not echoed back. The value is ciphertext-shaped and did not
    // verify, so handing it to a caller only ever puts base64 on a screen —
    // which is precisely what a phone missing its keyring used to render.
    expect($result['value'])->toBe('');
});

it('a relabeled table/field association still fails to decrypt (AD epoch binding, defense in depth)', function (): void {
    $userId = (int) codecUser('codec-relabel')->id;

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $keyringService->generateAndPersist($userId, $session);

    $ciphertext = $codec->encryptValue('transactions', 'note', 'secret note', $userId, $session);

    // Same ciphertext, but decrypted under a DIFFERENT field name — the
    // associated data no longer matches, so the AEAD auth tag must fail.
    $result = $codec->decryptValue('transactions', 'counterparty_name', $ciphertext, $userId, $session);
    expect($result['decrypted'])->toBeFalse();
    expect($result['value'])->toBe('');
});

it('is a pass-through in both directions when encryption is not enabled for the user', function (): void {
    $userId = (int) codecUser('codec-not-enabled')->id;

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    // No generateAndPersist() call — no sync_encryption_state row for this user.
    $plain = $codec->encryptValue('transactions', 'note', 'not yet encrypted', $userId, $session);
    expect($plain)->toBe('not yet encrypted');

    $result = $codec->decryptValue('transactions', 'note', 'not yet encrypted', $userId, $session);
    expect($result['decrypted'])->toBeFalse();
    expect($result['value'])->toBe('not yet encrypted');
});

it('encryptAttrs/decryptRow round-trip every sensitive column present and skip non-sensitive columns', function (): void {
    $userId = (int) codecUser('codec-attrs')->id;

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $keyringService->generateAndPersist($userId, $session);

    $attrs = [
        'description' => 'ALBERT HEIJN 1234',
        'counterparty_name' => 'Albert Heijn',
        'amount_minor' => 1234,
        'currency' => 'EUR',
    ];

    $encrypted = $codec->encryptAttrs('transactions', $attrs, $userId, $session);

    expect($encrypted['description'])->not->toBe('ALBERT HEIJN 1234');
    expect($encrypted['counterparty_name'])->not->toBe('Albert Heijn');
    expect($encrypted['amount_minor'])->toBe(1234);
    expect($encrypted['currency'])->toBe('EUR');

    $decrypted = $codec->decryptRow('transactions', $encrypted, $userId, $session);
    expect($decrypted['description'])->toBe('ALBERT HEIJN 1234');
    expect($decrypted['counterparty_name'])->toBe('Albert Heijn');
    expect($decrypted['amount_minor'])->toBe(1234);
    expect($decrypted['currency'])->toBe('EUR');
});

// The empty string is what a caller used to read "this row is sealed" from, and
// it is also what a genuinely blank note decrypts to. Both arms below come back
// '' — only the flag separates them, and every caller that guessed from the
// value alone got the first arm wrong.
it('separates a column whose plaintext is genuinely empty from one it could not open', function (): void {
    $userId = (int) codecUser('codec-empty-plaintext')->id;

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $keyringService->generateAndPersist($userId, $session);

    $sealedEmptyNote = $codec->encryptValue('transactions', 'note', '', $userId, $session);
    expect($sealedEmptyNote)->not->toBe('');

    $readable = $codec->decryptRow('transactions', ['note' => $sealedEmptyNote], $userId, $session);
    expect($readable['note'])->toBe('');
    expect($readable->isUnreadable('note'))->toBeFalse();
    expect($readable->hasUnreadable())->toBeFalse();

    $strandedNote = substr($sealedEmptyNote, 0, -4).'xxxx';
    $stranded = $codec->decryptRow('transactions', ['note' => $strandedNote], $userId, $session);
    expect($stranded['note'])->toBe('');
    expect($stranded->isUnreadable('note'))->toBeTrue();
    expect($stranded->hasUnreadable())->toBeTrue();
});

// A column the registry has no opinion about is never named unreadable, whether
// or not it happens to look like base64 — the flag reports what this codec
// blanked, not what a row happens to hold.
it('names only registered columns it had to blank', function (): void {
    $userId = (int) codecUser('codec-unregistered')->id;

    /** @var GdkKeyringService $keyringService */
    $keyringService = $this->app->make(GdkKeyringService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var SensitiveColumnCodec $codec */
    $codec = $this->app->make(SensitiveColumnCodec::class);

    $keyringService->generateAndPersist($userId, $session);

    $row = $codec->decryptRow('transactions', [
        'currency' => base64_encode(str_repeat("\x11", 64)),
        'amount_minor' => 1234,
        'description' => 'never encrypted',
    ], $userId, $session);

    expect($row->hasUnreadable())->toBeFalse();
    expect($row->isUnreadable('currency'))->toBeFalse();
    expect($row['currency'])->toBe(base64_encode(str_repeat("\x11", 64)));
    expect($row['amount_minor'])->toBe(1234);
    expect($row['description'])->toBe('never encrypted');
});
